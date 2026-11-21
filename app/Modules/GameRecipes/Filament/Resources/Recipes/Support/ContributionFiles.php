<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Support;

use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * The LAN files a recipe pins, as the review queue needs them: which uploads
 * still wait for approval, which were rejected, and whether a required base
 * archive has nothing to offer at all.
 *
 * Everything here reads the already loaded `files.sharedFile` relation, so a
 * table row costs no query (the resource eager-loads it for the list).
 */
final class ContributionFiles
{
    /**
     * Pinned uploads that still wait for the files review. Approving them is
     * the first half of "Dateien freigeben und veröffentlichen".
     *
     * @return Collection<int, SharedFile>
     */
    public static function pending(GameRecipe $recipe): Collection
    {
        return self::withVisibility($recipe, FileVisibility::Pending);
    }

    /**
     * Pinned uploads whose bytes are gone: they block publishing until unlinked.
     *
     * @return Collection<int, SharedFile>
     */
    public static function rejected(GameRecipe $recipe): Collection
    {
        return self::withVisibility($recipe, FileVisibility::Rejected);
    }

    /** @return array<int, SharedFile> pinned uploads by file row id, whatever their visibility */
    public static function pinned(GameRecipe $recipe): array
    {
        $pinned = [];

        foreach (self::files($recipe) as $file) {
            $shared = $file->sharedFile;

            if ($shared instanceof SharedFile) {
                $pinned[$file->id] = $shared;
            }
        }

        return $pinned;
    }

    /** The compact state the list shows for a shared game; null when there is nothing to report. */
    public static function state(GameRecipe $recipe): ?ContributionFileState
    {
        if (! $recipe->isContribution()) {
            return null;
        }

        if (self::rejected($recipe)->isNotEmpty()) {
            return ContributionFileState::Rejected;
        }

        if (self::pending($recipe)->isNotEmpty()) {
            return ContributionFileState::Pending;
        }

        return self::baseMissing($recipe) ? ContributionFileState::Missing : null;
    }

    /**
     * "name (size)" per upload, for the confirmation text of the approve-and-
     * publish action.
     *
     * @param  Collection<int, SharedFile>  $files
     * @return list<string>
     */
    public static function describe(Collection $files): array
    {
        $lines = [];

        foreach ($files as $file) {
            $lines[] = $file->original_name.' ('.Number::fileSize($file->size_bytes, precision: 1).')';
        }

        return $lines;
    }

    /** @return Collection<int, SharedFile> */
    private static function withVisibility(GameRecipe $recipe, FileVisibility $visibility): Collection
    {
        $matches = [];

        foreach (self::files($recipe) as $file) {
            $shared = $file->sharedFile;

            if ($shared instanceof SharedFile && $shared->visibility === $visibility) {
                $matches[] = $shared;
            }
        }

        return new Collection($matches);
    }

    /**
     * A required base archive with no pinned upload. Name matching would need
     * the Files module, so the list only claims "missing" when the one file the
     * install plan has to unpack is not there.
     */
    private static function baseMissing(GameRecipe $recipe): bool
    {
        $rows = array_values(array_filter(
            self::files($recipe),
            static fn (GameRecipeFile $file): bool => $file->role === RecipeFileRole::Base,
        ));

        if ($rows !== []) {
            foreach ($rows as $row) {
                if ($row->required && $row->shared_file_id === null) {
                    return true;
                }
            }

            return false;
        }

        // A contribution whose file rows never arrived still needs its archive.
        foreach ($recipe->requirements ?? [] as $requirement) {
            if (($requirement['role'] ?? null) !== RecipeFileRole::Base->value) {
                continue;
            }

            if ((bool) ($requirement['required'] ?? true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<GameRecipeFile> */
    private static function files(GameRecipe $recipe): array
    {
        $files = $recipe->relationLoaded('files')
            ? $recipe->files
            : $recipe->files()->with('sharedFile')->get();

        return array_values($files->all());
    }
}
