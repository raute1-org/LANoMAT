<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Actions;

use App\Modules\GameRecipes\Exceptions\RecipeException;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Support\RecipeParser;
use App\Modules\GameRecipes\Support\RecipeSourceLocator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Upserts every recipe in a source directory. The YAML is the authority;
 * a resync preserves the admin-set pins (`shared_file_id`) and the review
 * state (`status=draft` stays draft until an orga publishes it) so a nightly
 * sync of the NAS share never undoes a moderation decision.
 *
 * `--only` narrows what is *updated*, never what counts as present: a recipe
 * that still has a directory in the source is kept, even when it was not
 * selected or its file failed validation. `--prune` therefore only deletes
 * rows of this source kind whose slug has no recipe directory any more —
 * and nothing at all when the source yields no recipe directory (an empty or
 * unreadable source must never look like "everything was deleted").
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
        $kind = $this->sourceKind($source);
        $only = $only !== null && $only !== '' ? $only : null;
        $created = 0;
        $updated = 0;
        $skipped = [];
        /** @var list<string> $present */
        $present = [];

        try {
            foreach ($this->recipeFiles($materialized['path']) as $yamlPath) {
                try {
                    $payload = $this->parser->parseFile($yamlPath, $kind, $source);
                } catch (RecipeException $exception) {
                    $skipped[] = $yamlPath.': '.$exception->summary();

                    // An invalid file is a warning, not a deletion: its slug
                    // still has a recipe directory, so --prune must keep it.
                    $slug = $this->slugOfUnparsedFile($yamlPath);

                    if ($slug !== null) {
                        $present[] = $slug;
                    }

                    continue;
                }

                $slug = (string) $payload['recipe']['slug'];
                $present[] = $slug;

                if ($only !== null && $slug !== $only) {
                    continue;
                }

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

            $present = array_values(array_unique($present));

            if ($only !== null && ! in_array($only, $present, true)) {
                $skipped[] = sprintf('%s: the source has no recipe with this slug', $only);
            }

            $pruned = 0;

            if ($prune) {
                if ($present === []) {
                    // An empty or unreadable source is not a deleted catalogue.
                    $skipped[] = 'Nothing pruned: the source has no recipe directories.';
                } else {
                    $pruned = GameRecipe::query()
                        ->where('source', $kind)
                        ->whereNotIn('slug', $present)
                        ->delete();
                }
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

    /**
     * The slug of a recipe file the parser refused. The file itself is read
     * leniently first (`slug:` survives most schema errors), then the name of
     * its directory, which is the catalogue's slug convention. Null only when
     * nothing usable is left, so a broken file cannot empty the present set.
     */
    private function slugOfUnparsedFile(string $yamlPath): ?string
    {
        try {
            $data = Yaml::parseFile($yamlPath);
        } catch (ParseException) {
            $data = null;
        }

        $slug = is_array($data) && is_string($data['slug'] ?? null) ? trim($data['slug']) : '';

        if ($slug !== '') {
            return $slug;
        }

        $name = basename($yamlPath, '.yaml');

        if ($name !== '' && $name !== 'recipe') {
            return $name;
        }

        $directory = basename(dirname($yamlPath));

        return $directory !== '' && $directory !== '.' ? $directory : null;
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
