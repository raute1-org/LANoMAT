<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Modules\GameRecipes\Models\GameRecipe;

/**
 * The one "which recipe runs this game?" test: the server browser, the games
 * page and the install block all ask here instead of each keeping its own
 * slug/alias comparison.
 *
 * The ground truth is the game's slug. When a recipe's own slug equals it, the
 * match is certain and wins over every alias/title match; otherwise the
 * recipe's {@see GameRecipe::searchTerms()} are compared against the game's
 * name and slug with the rules the games page always used (a term appears in
 * the name/slug, or the ground-truth slug appears in the term). Names are
 * lowercased; empty game identities never match.
 */
final class RecipeGameMatcher
{
    public function matches(GameRecipe $recipe, ?string $gameSlug, ?string $gameName): bool
    {
        $slug = mb_strtolower(trim((string) $gameSlug));

        if ($slug !== '' && $slug === mb_strtolower($recipe->slug)) {
            return true;
        }

        return $this->matchesTerms($recipe->searchTerms(), $gameName, $gameSlug);
    }

    /**
     * The best recipe for a game: an exact slug match first, otherwise the
     * first recipe whose terms match, in the given iteration order.
     *
     * @param  iterable<mixed>  $recipes
     */
    public function find(iterable $recipes, ?string $gameSlug, ?string $gameName): ?GameRecipe
    {
        if (trim((string) $gameSlug) === '' && trim((string) $gameName) === '') {
            return null;
        }

        $needle = mb_strtolower(trim((string) $gameSlug));
        $fallback = null;

        foreach ($recipes as $recipe) {
            if (! $recipe instanceof GameRecipe) {
                continue;
            }

            if ($needle !== '' && mb_strtolower($recipe->slug) === $needle) {
                return $recipe;
            }

            if ($fallback === null && $this->matchesTerms($recipe->searchTerms(), $gameName, $gameSlug)) {
                $fallback = $recipe;
            }
        }

        return $fallback;
    }

    /**
     * Case-insensitive "same game" test: a search term (slug, title, alias)
     * must appear in the name/slug, or the ground-truth slug must appear in the
     * term. Empty names never match — the old `str_contains($term, '')` path
     * listed a game-less tournament on every recipe.
     *
     * @param  list<string>  $terms
     */
    private function matchesTerms(array $terms, ?string $name, ?string $slug): bool
    {
        $name = mb_strtolower((string) $name);
        $slug = mb_strtolower((string) $slug);

        if ($name === '' && $slug === '') {
            return false;
        }

        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }

            if (str_contains($name, $term) || str_contains($slug, $term)) {
                return true;
            }

            if ($slug !== '' && str_contains($term, $slug)) {
                return true;
            }
        }

        return false;
    }
}
