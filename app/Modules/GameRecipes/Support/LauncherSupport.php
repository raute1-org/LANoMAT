<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Models\GameRecipe;

/**
 * Whether the native launcher can take a recipe from "Installieren" to a
 * running game on its own. The one place that decides `launcher_supported`
 * for the REST catalogue, the games page, the home chat, the launcher page
 * and the server browser.
 *
 * The launcher downloads and unpacks archives, checks files and starts a
 * launch target. It never runs a recipe's step commands. So a recipe is
 * supported when one of these holds:
 *
 * - it is the UT2004 pilot, which has a dedicated engine path;
 * - it was verified by hand with the generic engine (VERIFIED_SLUGS);
 * - it is a browser recipe (`play_url`): there is nothing to install;
 * - it declares `metadata.portable: true` and has the shape the generic
 *   engine needs: a required base archive, a file or folder check and a
 *   launch target. "Portable" is the author's promise that unpacking the
 *   base archive is the whole install.
 *
 * `metadata.launcher_supported` (boolean) overrides all of it, for a recipe
 * the orga has tried and wants to switch on or off by hand.
 */
final class LauncherSupport
{
    public const PILOT_SLUG = 'unreal-tournament-2004';

    /** @var list<string> */
    public const VERIFIED_SLUGS = ['need-for-speed-underground-2'];

    public function supports(GameRecipe $recipe): bool
    {
        $metadata = is_array($recipe->metadata) ? $recipe->metadata : [];
        $override = $metadata['launcher_supported'] ?? null;

        if (is_bool($override)) {
            return $override;
        }

        if ($recipe->slug === self::PILOT_SLUG || in_array($recipe->slug, self::VERIFIED_SLUGS, true)) {
            return true;
        }

        if ($recipe->playUrl() !== null) {
            return true;
        }

        return $this->isPortable($recipe);
    }

    /** Whether the recipe is a ready-to-run archive the generic engine installs without commands. */
    public function isPortable(GameRecipe $recipe): bool
    {
        $metadata = is_array($recipe->metadata) ? $recipe->metadata : [];

        return ($metadata['portable'] ?? null) === true
            && $this->hasRequiredBase($recipe)
            && $this->hasFileCheck($recipe)
            && $recipe->launchTargets() !== [];
    }

    private function hasRequiredBase(GameRecipe $recipe): bool
    {
        foreach ((array) $recipe->requirements as $requirement) {
            $isBase = ($requirement['role'] ?? null) === RecipeFileRole::Base->value;

            // Same default as the YAML parser: a base file is required unless it says otherwise.
            if ($isBase && (bool) ($requirement['required'] ?? true)) {
                return true;
            }
        }

        return false;
    }

    private function hasFileCheck(GameRecipe $recipe): bool
    {
        foreach ((array) $recipe->steps as $step) {
            if ((bool) ($step['optional'] ?? false)) {
                continue;
            }

            foreach ((array) ($step['verify'] ?? []) as $check) {
                if (is_array($check)
                    && in_array($check['type'] ?? null, ['file_exists', 'dir_exists'], true)
                    && is_string($check['path'] ?? null)
                    && trim($check['path']) !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
