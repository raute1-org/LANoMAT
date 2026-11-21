<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Actions;

use App\Models\User;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\RecipeValidator;
use App\Modules\GameRecipes\Support\SkillRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Creates or updates a contributor's draft recipe (MCP tool and REST endpoint share this). */
final class ContributeRecipe
{
    public function __construct(
        private readonly RecipeValidator $validator,
        private readonly SkillRenderer $skills,
    ) {}

    /** @param array<string, mixed> $payload */
    public function handle(User $user, array $payload): GameRecipe
    {
        $slug = (string) ($payload['slug'] ?? '');
        $existing = GameRecipe::query()->where('slug', $slug)->first();

        if ($existing instanceof GameRecipe) {
            // A contributor may only edit their own *draft*. Without this guard
            // any paired participant could rewrite a curated recipe (steps are
            // shell commands on every installer) and silently unpublish it.
            if (Gate::forUser($user)->denies('update', $existing)) {
                throw new AuthorizationException('You may only edit your own draft recipe.');
            }
        }

        $recipe = [
            'schema' => RecipeValidator::SCHEMA,
            'slug' => $slug,
            'title' => (string) ($payload['title'] ?? ''),
            'aliases' => array_values(array_map('strval', (array) ($payload['aliases'] ?? []))),
            'version' => isset($payload['version']) ? (string) $payload['version'] : null,
            'status' => $existing instanceof GameRecipe ? $existing->status->value : RecipeStatus::Draft->value,
            'platforms' => array_values(array_map('strval', (array) ($payload['platforms'] ?? ['windows']))),
            'summary' => $payload['summary'] ?? null,
            'description' => $payload['description'] ?? null,
            'homepage' => $payload['homepage'] ?? null,
            'license' => $payload['license'] ?? null,
            'skill' => [
                'name' => $slug,
                'description' => (string) ($payload['skill_description'] ?? 'Install the game from the LAN LANoMAT recipe.'),
            ],
            'requirements' => array_values((array) ($payload['requires'] ?? $payload['requirements'] ?? [])),
            'steps' => array_values((array) ($payload['steps'] ?? [])),
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : ['contributed_via' => 'mcp'],
        ];

        $validator = Validator::make($payload, [
            'title' => ['required', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:500'],
            'aliases' => ['nullable', 'array', 'max:20'],
            'aliases.*' => ['string', 'max:64'],
            'platforms' => ['nullable', 'array', 'max:3'],
            'requirements' => ['nullable', 'array', 'max:50'],
            'steps' => ['nullable', 'array', 'max:50'],
            'skill_description' => ['nullable', 'string', 'max:1024'],
            'skill_markdown' => ['nullable', 'string', 'max:20000'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        if (isset($payload['skill_markdown']) && is_string($payload['skill_markdown']) && $payload['skill_markdown'] !== '') {
            $skillMarkdown = $payload['skill_markdown'];
        } elseif (isset($payload['skill']) && is_array($payload['skill']) && is_string($payload['skill']['description'] ?? null)) {
            $recipe['skill'] = $payload['skill'];
            $skillMarkdown = $this->skills->render($recipe);
        } else {
            $skillMarkdown = $this->skills->render($recipe);
        }

        $this->validator->validate($recipe, 'contribution:'.$slug);

        $model = GameRecipe::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'title' => $recipe['title'],
                'aliases' => $recipe['aliases'],
                'version' => $recipe['version'],
                'status' => $existing instanceof GameRecipe ? $existing->status : RecipeStatus::Draft,
                'source' => $existing instanceof GameRecipe ? $existing->source : 'contribution',
                'source_ref' => $existing instanceof GameRecipe ? $existing->source_ref : 'mcp',
                'platforms' => $recipe['platforms'],
                'summary' => $recipe['summary'],
                'description' => $recipe['description'],
                'homepage' => $recipe['homepage'],
                'license' => $recipe['license'],
                'skill_markdown' => $skillMarkdown,
                'requirements' => $recipe['requirements'],
                'steps' => $recipe['steps'],
                'metadata' => $recipe['metadata'],
                'contributor_user_id' => $existing instanceof GameRecipe ? $existing->contributor_user_id : $user->id,
            ],
        );

        $model->files()->delete();

        foreach ($recipe['requirements'] as $index => $requirement) {
            if (! is_array($requirement)) {
                continue;
            }

            $model->files()->create([
                'role' => (string) ($requirement['role'] ?? 'content'),
                'label' => (string) ($requirement['label'] ?? 'File'),
                'match_patterns' => array_values(array_map('strval', (array) ($requirement['match'] ?? []))),
                'size_bytes' => isset($requirement['size_bytes']) ? (int) $requirement['size_bytes'] : null,
                'sha256' => isset($requirement['sha256']) && (string) $requirement['sha256'] !== '' ? mb_strtolower((string) $requirement['sha256']) : null,
                'url' => isset($requirement['url']) ? (string) $requirement['url'] : null,
                'required' => (bool) ($requirement['required'] ?? false),
                'sort' => $index,
                'metadata' => array_filter([
                    'author' => $requirement['author'] ?? null,
                    'license' => $requirement['license'] ?? null,
                    'notes' => $requirement['notes'] ?? null,
                    'tags' => $requirement['tags'] ?? null,
                ], static fn (mixed $value): bool => $value !== null && $value !== []),
            ]);
        }

        return $model->refresh();
    }
}
