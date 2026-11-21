<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Listeners;

use App\Modules\GameRecipes\Contracts\RecipeRepository;
use App\Modules\GameRecipes\Events\RecipePublished;
use App\Modules\GameRecipes\Jobs\ProposeRecipePullRequestJob;
use App\Modules\GameRecipes\Models\GameRecipe;

/**
 * Publishing a shared or panel-authored recipe always opens or updates its
 * pull request: with `trigger = publish` this is the moment it is opened, with
 * `trigger = contribute` it carries the new status into the catalogue row.
 * A recipe a sync adopted keeps its pull request up to date through the same
 * route. No IO here; the queued job proposes.
 */
final class ProposePublishedRecipe
{
    public function __construct(private readonly RecipeRepository $repository) {}

    public function handle(RecipePublished $event): void
    {
        if (! $this->repository->isConfigured()) {
            return;
        }

        $recipe = $event->recipe;

        $proposable = in_array($recipe->source, [GameRecipe::SOURCE_CONTRIBUTION, GameRecipe::SOURCE_PANEL], true)
            || $recipe->pr_number !== null;

        if (! $proposable) {
            return;
        }

        ProposeRecipePullRequestJob::dispatch((int) $recipe->getKey());
    }
}
