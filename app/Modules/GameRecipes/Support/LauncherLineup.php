<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use Illuminate\Support\Collection;

/**
 * The launcher's curated first lineup: the recipes the launcher, the web
 * library and the MCP catalogue show. `config('launcher.lineup')` is a comma
 * separated allowlist (`LAUNCHER_LINEUP`); the bundled default names the two
 * tested games. An empty value means "no curation" and keeps every visible
 * recipe in the catalogue, which is the back-compat path for an installation
 * that never sets the variable.
 *
 * Recipes outside the lineup stay in the database: the Filament admin, the
 * sync and the recipe APIs still see them. Only the player-facing read paths
 * filter through this class.
 */
final class LauncherLineup
{
    /** The tested games shipped as the default lineup. */
    public const DEFAULT = [
        'unreal-tournament-2004',
        'need-for-speed-underground-2',
        'need-for-speed-underground-2-browser',
    ];

    /**
     * The configured allowlist, lowercased and de-duplicated. Unknown or
     * malformed entries are ignored: a typo in the env value must not hide
     * the whole catalogue, and an empty result means "unrestricted".
     *
     * @return list<string>
     */
    public function slugs(): array
    {
        $configured = config('launcher.lineup');

        $parts = is_array($configured)
            ? $configured
            : (is_string($configured) ? explode(',', $configured) : []);

        $slugs = [];

        foreach ($parts as $part) {
            if (! is_string($part)) {
                continue;
            }

            $slug = mb_strtolower(trim($part));

            if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
                continue;
            }

            $slugs[] = $slug;
        }

        return array_values(array_unique($slugs));
    }

    /** Whether the allowlist is empty, i.e. every visible recipe may be shown. */
    public function isUnrestricted(): bool
    {
        return $this->slugs() === [];
    }

    public function allows(string $slug): bool
    {
        $slugs = $this->slugs();

        return $slugs === [] || in_array(mb_strtolower($slug), $slugs, true);
    }

    /**
     * Filter a recipe collection by the allowlist. The collection may be an
     * Eloquent collection; the keys are re-indexed so callers keep their
     * `values()`-style assumptions.
     *
     * @template TKey of array-key
     * @template TValue of object
     *
     * @param  Collection<TKey, TValue>  $recipes
     * @return Collection<int, TValue>
     */
    public function filter(Collection $recipes): Collection
    {
        if ($this->isUnrestricted()) {
            return $recipes->values();
        }

        $slugs = $this->slugs();

        /** @var Collection<int, TValue> $filtered */
        $filtered = $recipes
            ->filter(static fn (object $recipe): bool => isset($recipe->slug)
                && in_array(mb_strtolower((string) $recipe->slug), $slugs, true))
            ->values();

        return $filtered;
    }
}
