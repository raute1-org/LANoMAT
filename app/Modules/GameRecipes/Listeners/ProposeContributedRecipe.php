<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Listeners;

use App\Modules\GameRecipes\Actions\ProposeRecipe;
use App\Modules\GameRecipes\Contracts\RecipeRepository;
use App\Modules\GameRecipes\Events\RecipeContributed;
use App\Modules\GameRecipes\Jobs\ProposeRecipePullRequestJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Opens the pull request as soon as a participant shares, when the operator
 * chose `trigger = contribute`. With the default `trigger = publish` the
 * contribution waits for the orga. The listener does no IO itself beyond the
 * budget check: everything that talks to the repository happens in the queued
 * job, queued only after the contribution's transaction has committed.
 */
final class ProposeContributedRecipe
{
    public function __construct(
        private readonly RecipeRepository $repository,
        private readonly ProposeRecipe $action,
    ) {}

    public function handle(RecipeContributed $event): void
    {
        if (! $this->repository->isConfigured()) {
            return;
        }

        if (config('recipes.pull_requests.trigger') !== 'contribute') {
            return;
        }

        $recipe = $event->recipe;

        if (! $recipe->isContribution() || ! $this->action->isProposable($recipe)) {
            return;
        }

        $reason = $this->action->limitReason($recipe, $event->userId);

        if ($reason !== null) {
            Log::notice('recipes.pull_request_skipped', [
                'slug' => $recipe->slug,
                'reason' => $reason,
                'user_id' => $event->userId,
            ]);

            return;
        }

        DB::afterCommit(
            static fn () => ProposeRecipePullRequestJob::dispatch((int) $recipe->getKey()),
        );
    }
}
