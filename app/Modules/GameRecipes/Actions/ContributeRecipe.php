<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Actions;

use App\Models\User;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Events\RecipeContributed;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Support\RecipeValidator;
use App\Modules\GameRecipes\Support\SkillRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a contributor's draft recipe. The web share page, the MCP
 * tool and the REST endpoint all end here.
 *
 * The payload has the YAML shape (`requires`, `steps`, `launch_targets`,
 * `metadata`, …). Two keys exist only on this path: `via` (`web`, `mcp` or
 * `api`, where the contribution came from) and, per requirement,
 * `shared_file_id`, which pins a LAN file the contributor may see.
 */
final class ContributeRecipe
{
    /** Longest metadata document a contribution may carry, as JSON. */
    private const MAX_METADATA_BYTES = 20000;

    public function __construct(
        private readonly RecipeValidator $validator,
        private readonly SkillRenderer $skills,
    ) {}

    /** @param array<string, mixed> $payload */
    public function handle(User $user, array $payload): GameRecipe
    {
        $slug = (string) ($payload['slug'] ?? '');

        // Free text that becomes a heading, a YAML scalar or a table cell
        // stays on one line: a line break in a title could otherwise end the
        // SKILL.md front matter or start a new Markdown block.
        $oneLine = 'not_regex:/[\x00-\x1F\x7F]/';

        $validator = Validator::make($payload, [
            // `required` is decided by RecipeValidator, which reports every
            // problem in one pass (slug, title, platforms, steps, …).
            'title' => ['nullable', 'string', 'max:200', $oneLine],
            'summary' => ['nullable', 'string', 'max:500', $oneLine],
            'version' => ['nullable', 'string', 'max:40', $oneLine],
            'homepage' => ['nullable', 'url:http,https', 'max:500'],
            'license' => ['nullable', 'string', 'max:200', $oneLine],
            'aliases' => ['nullable', 'array', 'max:20'],
            'aliases.*' => ['string', 'max:64', $oneLine],
            'platforms' => ['nullable', 'array', 'max:3'],
            'requirements' => ['nullable', 'array', 'max:50'],
            'requires' => ['nullable', 'array', 'max:50'],
            'steps' => ['nullable', 'array', 'max:50'],
            'launch_targets' => ['nullable', 'array', 'max:10'],
            'metadata' => ['nullable', 'array'],
            'skill_description' => ['nullable', 'string', 'max:1024', $oneLine],
            'skill_markdown' => ['nullable', 'string', 'max:65535'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $via = in_array($payload['via'] ?? null, ['web', 'mcp', 'api'], true) ? (string) $payload['via'] : 'mcp';

        $model = DB::transaction(function () use ($user, $payload, $slug, $via): GameRecipe {
            // One writer per slug: two people sharing the same title at the
            // same moment must not both see "no such recipe" and then take
            // each other's draft. The lock is held until the commit, and the
            // row is read under it.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['recipe:'.$slug]);

            $existing = GameRecipe::query()->where('slug', $slug)->first();

            if ($existing instanceof GameRecipe) {
                // A contributor may only edit their own *draft*. Without this guard
                // any paired participant could rewrite a curated recipe (steps are
                // shell commands on every installer) and silently unpublish it.
                if (Gate::forUser($user)->denies('update', $existing)) {
                    throw new AuthorizationException('You may only edit your own draft recipe.');
                }
            } else {
                $this->ensureDraftBudget($user);
            }

            $requirements = array_values((array) ($payload['requires'] ?? $payload['requirements'] ?? []));
            $pins = $this->requestedPins($user, $requirements);

            // A pin is this installation's choice of file, not recipe text: it
            // lives on the file row and never in the stored requirement.
            $requirements = array_map(static function (mixed $requirement): mixed {
                if (is_array($requirement)) {
                    unset($requirement['shared_file_id']);
                }

                return $requirement;
            }, $requirements);

            $recipe = [
                'schema' => RecipeValidator::SCHEMA,
                'slug' => $slug,
                'title' => (string) ($payload['title'] ?? ''),
                'aliases' => array_values(array_map('strval', (array) ($payload['aliases'] ?? []))),
                'version' => isset($payload['version']) ? (string) $payload['version'] : null,
                'status' => $existing instanceof GameRecipe ? $existing->status->value : RecipeStatus::Draft->value,
                'platforms' => array_values(array_map('strval', (array) ($payload['platforms'] ?? ['windows']))),
                'summary' => isset($payload['summary']) && is_string($payload['summary']) ? mb_substr($payload['summary'], 0, 500) : null,
                'description' => isset($payload['description']) && is_string($payload['description']) ? mb_substr($payload['description'], 0, 10000) : null,
                'homepage' => $payload['homepage'] ?? null,
                'license' => $payload['license'] ?? null,
                'skill' => [
                    'name' => $slug,
                    'description' => (string) ($payload['skill_description'] ?? 'Install the game from the LAN LANoMAT recipe.'),
                ],
                'requirements' => $requirements,
                'steps' => array_values((array) ($payload['steps'] ?? [])),
                'metadata' => $this->metadata($user, $payload, $existing, $via),
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

            // An admin's pin survives a contributor's edit, exactly as it
            // survives a directory sync: it is keyed by role and label.
            $kept = $existing instanceof GameRecipe
                ? $existing->files()->get()
                    ->filter(static fn (GameRecipeFile $file): bool => $file->shared_file_id !== null)
                    ->mapWithKeys(static fn (GameRecipeFile $file): array => [$file->role->value.'|'.$file->label => $file->shared_file_id])
                    ->all()
                : [];

            $model = GameRecipe::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'title' => $recipe['title'],
                    'aliases' => $recipe['aliases'],
                    'version' => $recipe['version'],
                    'status' => $existing instanceof GameRecipe ? $existing->status : RecipeStatus::Draft,
                    'source' => $existing instanceof GameRecipe ? $existing->source : GameRecipe::SOURCE_CONTRIBUTION,
                    'source_ref' => $existing instanceof GameRecipe ? $existing->source_ref : $via,
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

                $role = (string) ($requirement['role'] ?? 'content');
                $label = (string) ($requirement['label'] ?? 'File');

                $model->files()->create([
                    'role' => $role,
                    'label' => $label,
                    'match_patterns' => array_values(array_map('strval', (array) ($requirement['match'] ?? []))),
                    'size_bytes' => isset($requirement['size_bytes']) ? (int) $requirement['size_bytes'] : null,
                    'sha256' => isset($requirement['sha256']) && (string) $requirement['sha256'] !== '' ? mb_strtolower((string) $requirement['sha256']) : null,
                    'url' => isset($requirement['url']) && (string) $requirement['url'] !== '' ? (string) $requirement['url'] : null,
                    // Same default as the YAML parser: the base file blocks the install.
                    'required' => (bool) ($requirement['required'] ?? in_array($role, [RecipeFileRole::Base->value, RecipeFileRole::Iso->value], true)),
                    'sort' => $index,
                    'shared_file_id' => $pins[$index] ?? $kept[$role.'|'.$label] ?? null,
                    'metadata' => array_filter([
                        // Same contract as the YAML parser: `os` narrows the file
                        // to one platform, absent means every plan sees it.
                        'os' => isset($requirement['os']) && (string) $requirement['os'] !== ''
                            ? mb_strtolower((string) $requirement['os'])
                            : null,
                        'author' => $requirement['author'] ?? null,
                        'license' => $requirement['license'] ?? null,
                        'notes' => $requirement['notes'] ?? null,
                        'tags' => $requirement['tags'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null && $value !== []),
                ]);
            }

            return $model->refresh();
        });

        RecipeContributed::dispatch($model, $user->id);

        return $model;
    }

    /**
     * A participant holds a bounded number of drafts that wait for review.
     * Without it, one paired device could create recipes (and, with pull
     * requests on, branches in the repository) as fast as the API allows.
     */
    private function ensureDraftBudget(User $user): void
    {
        if ($user->isOrga()) {
            return;
        }

        $max = max(1, (int) config('recipes.max_drafts_per_user', 20));

        $open = GameRecipe::query()
            ->where('contributor_user_id', $user->id)
            ->where('status', RecipeStatus::Draft->value)
            ->count();

        if ($open >= $max) {
            throw ValidationException::withMessages([
                'title' => __('recipe_contributions.errors.too_many_drafts', ['max' => $max]),
            ]);
        }
    }

    /**
     * The LAN files the payload pins, by requirement index. A contributor may
     * pin a file they could download anyway (their own upload or an approved
     * one); anything else is refused, so a recipe can never be used to learn
     * about or reach somebody else's pending upload.
     *
     * @param  list<mixed>  $requirements
     * @return array<int, int>
     */
    private function requestedPins(User $user, array $requirements): array
    {
        $pins = [];

        foreach ($requirements as $index => $requirement) {
            if (! is_array($requirement) || ! isset($requirement['shared_file_id']) || $requirement['shared_file_id'] === '') {
                continue;
            }

            $id = filter_var($requirement['shared_file_id'], FILTER_VALIDATE_INT);
            $file = $id === false ? null : SharedFile::query()->find($id);

            // "May download" is the rule an install follows: an approved file,
            // or the contributor's own upload that still waits for approval;
            // never a rejected one and never a file of an event that is not
            // public yet.
            if (! $file instanceof SharedFile || Gate::forUser($user)->denies('download', $file)) {
                throw ValidationException::withMessages([
                    "requirements.{$index}.shared_file_id" => 'This file is not available to you.',
                ]);
            }

            $pins[$index] = (int) $file->getKey();
        }

        return $pins;
    }

    /**
     * Recipe metadata for this write. A payload without metadata keeps what an
     * existing recipe has, so editing the steps does not wipe the launch
     * targets. `launch_targets` and `play_url` may come at the top level, as
     * they do in recipe.yaml. `launcher_supported` is the orga's switch and is
     * ignored from anyone else.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function metadata(User $user, array $payload, ?GameRecipe $existing, string $via): array
    {
        $incoming = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null;
        $metadata = $incoming ?? ($existing instanceof GameRecipe && is_array($existing->metadata) ? $existing->metadata : []);

        if (is_array($payload['launch_targets'] ?? null) && $payload['launch_targets'] !== []) {
            $metadata['launch_targets'] = array_values($payload['launch_targets']);
        }

        if (is_string($payload['play_url'] ?? null) && $payload['play_url'] !== '') {
            $metadata['play_url'] = $payload['play_url'];
        }

        if (! $user->isOrga()) {
            if ($existing instanceof GameRecipe && is_array($existing->metadata) && array_key_exists('launcher_supported', $existing->metadata)) {
                $metadata['launcher_supported'] = $existing->metadata['launcher_supported'];
            } else {
                unset($metadata['launcher_supported']);
            }
        }

        if (! $existing instanceof GameRecipe) {
            $metadata['contributed_via'] = $via;
        }

        // These become links on the games page; a contribution must not be
        // able to plant another scheme there.
        foreach (['play_url', 'live_url', 'avatar_converter_url'] as $key) {
            $url = $metadata[$key] ?? null;

            if ($url !== null && $url !== '' && (! is_string($url) || preg_match('#^https?://#i', $url) !== 1)) {
                throw ValidationException::withMessages(["metadata.{$key}" => 'This must be an http(s) URL.']);
            }
        }

        if (strlen((string) json_encode($metadata)) > self::MAX_METADATA_BYTES) {
            throw ValidationException::withMessages(['metadata' => 'The metadata is too large.']);
        }

        return $metadata;
    }
}
