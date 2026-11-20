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

/**
 * Creates or updates a contributor's draft recipe (MCP tool and REST endpoint share this).
 *
 * A contribution is keyed by slug, so the action must decide create vs update
 * on its own: without that check any token holder could overwrite — and
 * unpublish — somebody else's published recipe simply by reusing its slug.
 * The decision goes through GameRecipePolicy, the same rule the panel uses.
 */
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

        if ($existing instanceof GameRecipe && Gate::forUser($user)->denies('update', $existing)) {
            throw new AuthorizationException('Only an orga — or the contributor of their own draft — may change this recipe.');
        }

        $recipe = [
            'schema' => RecipeValidator::SCHEMA,
            'slug' => $slug,
            'title' => (string) ($payload['title'] ?? ''),
            'aliases' => array_values(array_map('strval', (array) ($payload['aliases'] ?? []))),
            'version' => isset($payload['version']) ? (string) $payload['version'] : null,
            'status' => RecipeStatus::Draft->value,
            'platforms' => array_values(array_map('strval', (array) ($payload['platforms'] ?? ['windows']))),
            'summary' => isset($payload['summary']) && is_string($payload['summary']) ? mb_substr($payload['summary'], 0, 500) : null,
            'description' => isset($payload['description']) && is_string($payload['description']) ? mb_substr($payload['description'], 0, 10000) : null,
            'homepage' => $payload['homepage'] ?? null,
            'license' => $payload['license'] ?? null,
            'skill' => [
                'name' => $slug,
                'description' => (string) ($payload['skill_description'] ?? 'Install the game from the LAN LANoMAT recipe.'),
            ],
            'requirements' => array_values((array) ($payload['requirements'] ?? [])),
            'steps' => array_values((array) ($payload['steps'] ?? [])),
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : ['contributed_via' => 'mcp'],
        ];

        if (isset($payload['skill_markdown']) && is_string($payload['skill_markdown']) && $payload['skill_markdown'] !== '') {
            $skillMarkdown = mb_substr($payload['skill_markdown'], 0, 65535);
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
                'status' => RecipeStatus::Draft,
                'source' => 'contribution',
                'source_ref' => 'mcp',
                'platforms' => $recipe['platforms'],
                'summary' => $recipe['summary'],
                'description' => $recipe['description'],
                'homepage' => $recipe['homepage'],
                'license' => $recipe['license'],
                'skill_markdown' => $skillMarkdown,
                'requirements' => $recipe['requirements'],
                'steps' => $recipe['steps'],
                'metadata' => $recipe['metadata'],
                'contributor_user_id' => $user->id,
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
