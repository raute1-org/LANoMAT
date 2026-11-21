<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Listeners;

use App\Modules\GameRecipes\Contracts\RecipeRepository;
use App\Modules\GameRecipes\Events\RecipeContributed;
use App\Modules\GameRecipes\Jobs\ProposeRecipePullRequestJob;

/**
 * Opens the pull request as soon as a participant shares, when the operator
 * chose `trigger = contribute`. With `trigger = publish` the contribution
 * waits for the orga. The listener does no IO itself: everything that talks to
 * the repository happens in the queued job.
 */
final class ProposeContributedRecipe
{
    public function __construct(private readonly RecipeRepository $repository) {}

    public function handle(RecipeContributed $event): void
    {
        if (! $this->repository->isConfigured()) {
            return;
        }

        if (config('recipes.pull_requests.trigger') !== 'contribute') {
            return;
        }

        if (! $event->recipe->isContribution()) {
            return;
        }

        ProposeRecipePullRequestJob::dispatch((int) $event->recipe->getKey());
    }
}
