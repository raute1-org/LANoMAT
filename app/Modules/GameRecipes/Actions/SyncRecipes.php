<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Actions;

use App\Modules\GameRecipes\Exceptions\RecipeException;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Support\RecipeParser;
use App\Modules\GameRecipes\Support\RecipeSourceLocator;
use Illuminate\Support\Facades\DB;

/**
 * Upserts every recipe in a source directory. The YAML is the authority;
 * a resync preserves the admin-set pins (`shared_file_id`) and the review
 * state (`status=draft` stays draft until an orga publishes it) so a nightly
 * sync of the NAS share never undoes a moderation decision.
 */
final class SyncRecipes
{
    public function __construct(
        private readonly RecipeSourceLocator $locator,
        private readonly RecipeParser $parser,
    ) {}

    /**
     * @return array{created: int, updated: int, pruned: int, skipped: list<string>, source: string}
     */
    public function handle(string $source, bool $prune = false, ?string $only = null): array
    {
        $materialized = $this->locator->materialize($source);
        $created = 0;
        $updated = 0;
        $skipped = [];
        $seen = [];

        try {
            foreach ($this->recipeFiles($materialized['path']) as $yamlPath) {
                try {
                    $payload = $this->parser->parseFile($yamlPath, $this->sourceKind($source), $source);
                } catch (RecipeException $exception) {
                    $skipped[] = $yamlPath.': '.$exception->summary();

                    continue;
                }

                $slug = (string) $payload['recipe']['slug'];

                if ($only !== null && $only !== '' && $slug !== $only) {
                    continue;
                }

                $seen[] = $slug;

                DB::transaction(function () use ($payload, &$created, &$updated): void {
                    $existing = GameRecipe::query()->where('slug', $payload['recipe']['slug'])->first();
                    $pins = $existing instanceof GameRecipe
                        ? $existing->files->mapWithKeys(fn (GameRecipeFile $file): array => [
                            $file->role->value.'|'.$file->label => $file->shared_file_id,
                        ])->all()
                        : [];

                    $attributes = $payload['recipe'];

                    if ($existing instanceof GameRecipe && $existing->status->value !== $attributes['status']) {
                        // A moderator's decision wins over the file on disk until
                        // the recipe is explicitly republished through the panel.
                        unset($attributes['status']);
                    }

                    if ($existing instanceof GameRecipe) {
                        $existing->fill($attributes)->save();
                        $updated++;
                    } else {
                        $existing = GameRecipe::query()->create($attributes);
                        $created++;
                    }

                    // Update rows in place (stable ids) so avatar selection,
                    // plan references and pins survive a nightly sync; only
                    // rows whose role+label disappeared are deleted.
                    $current = $existing->files()->get()->keyBy(
                        static fn (GameRecipeFile $file): string => $file->role->value.'|'.$file->label,
                    );
                    $kept = [];

                    foreach ($payload['files'] as $file) {
                        $pinKey = $file['role'].'|'.$file['label'];
                        $row = $current->get($pinKey);

                        if ($row instanceof GameRecipeFile) {
                            $file['shared_file_id'] = $row->shared_file_id ?? $pins[$pinKey] ?? null;
                            $row->fill($file)->save();
                            $kept[] = $row->id;
                        } else {
                            $file['shared_file_id'] = $pins[$pinKey] ?? null;
                            $kept[] = $existing->files()->create($file)->id;
                        }
                    }

                    $existing->files()->when($kept !== [], fn ($query) => $query->whereNotIn('id', $kept))->delete();
                });
            }

            $pruned = 0;

            if ($prune) {
                $pruned = GameRecipe::query()
                    ->where('source', $this->sourceKind($source))
                    ->when($seen !== [], fn ($query) => $query->whereNotIn('slug', $seen))
                    ->delete();
            }

            return [
                'created' => $created,
                'updated' => $updated,
                'pruned' => $pruned,
                'skipped' => $skipped,
                'source' => $materialized['ref'],
            ];
        } finally {
            $this->locator->cleanup($materialized['path'], $materialized['temporary']);
        }
    }

    /** @return list<string> */
    private function recipeFiles(string $root): array
    {
        $files = [];

        foreach (glob($root.'/*/recipe.yaml') ?: [] as $path) {
            $files[] = $path;
        }

        foreach (glob($root.'/*.yaml') ?: [] as $path) {
            if (basename($path) === 'recipe.yaml') {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    private function sourceKind(string $source): string
    {
        return match (true) {
            str_starts_with($source, 'smb://') => 'smb',
            str_starts_with($source, 'git+') || str_ends_with($source, '.git') => 'git',
            default => 'bundled',
        };
    }
}
