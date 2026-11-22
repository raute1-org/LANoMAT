<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Support;

use App\Modules\GameRecipes\Actions\ProposeRecipe;
use App\Modules\GameRecipes\Contracts\RecipeRepository;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Models\GameRecipe;

/**
 * The pull request readiness of one recipe, as the panel shows it: the same
 * checks {@see ProposeRecipe} runs before it sends, asked without a repository
 * call and without writing anything. The value is not cached — the form
 * section asks once per render, the publish confirmation asks when its modal
 * opens, and the list page never asks at all.
 */
final class CatalogPreview
{
    /**
     * Whether the panel has anything to say: pull requests are configured and
     * the pipeline would take this recipe at all.
     */
    public static function appliesTo(GameRecipe $recipe): bool
    {
        return app(RecipeRepository::class)->isConfigured()
            && app(ProposeRecipe::class)->isProposable($recipe);
    }

    /**
     * What would keep the recipe out of the repository; empty means ready.
     * `$as` asks for another status than the stored one ("and once published?").
     *
     * @return list<string>
     */
    public static function problems(GameRecipe $recipe, ?RecipeStatus $as = null): array
    {
        return app(ProposeRecipe::class)->preview($recipe, $as);
    }

    /**
     * The one warning line a publish confirmation carries when the pull
     * request will be refused at that moment, or null while it will go (or the
     * pipeline is off). Publishing itself is never blocked by it.
     */
    public static function publishWarning(GameRecipe $recipe): ?string
    {
        if (! self::appliesTo($recipe)) {
            return null;
        }

        $problems = self::problems($recipe, RecipeStatus::Published);

        if ($problems === []) {
            return null;
        }

        return __('game_recipes.actions.publish_pr_warning').': '
            .trans_choice('game_recipes.actions.publish_pr_warning_detail', count($problems), ['count' => count($problems)]);
    }
}
