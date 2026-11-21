<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Listeners;

use App\Modules\GameRecipes\Actions\ProposeRecipe;
use App\Modules\GameRecipes\Contracts\RecipeRepository;
use App\Modules\GameRecipes\Events\RecipePublished;
use App\Modules\GameRecipes\Jobs\ProposeRecipePullRequestJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Publishing a shared or panel-authored recipe always opens or updates its
 * pull request: with the default `trigger = publish` this is the moment it is
 * opened, with `trigger = contribute` it carries the new status into the
 * catalogue row. A recipe a sync adopted keeps its pull request up to date
 * through the same route.
 *
 * No IO here beyond the budget check; the job is queued after the publishing
 * transaction commits, because Filament dispatches the event inside its save.
 */
final class ProposePublishedRecipe
{
    public function __construct(
        private readonly RecipeRepository $repository,
        private readonly ProposeRecipe $action,
    ) {}

    public function handle(RecipePublished $event): void
    {
        if (! $this->repository->isConfigured()) {
            return;
        }

        $recipe = $event->recipe;

        if (! $this->action->isProposable($recipe)) {
            return;
        }

        $reason = $this->action->limitReason($recipe, $recipe->contributor_user_id);

        if ($reason !== null) {
            Log::notice('recipes.pull_request_skipped', [
                'slug' => $recipe->slug,
                'reason' => $reason,
                'user_id' => $recipe->contributor_user_id,
            ]);

            return;
        }

        DB::afterCommit(
            static fn () => ProposeRecipePullRequestJob::dispatch((int) $recipe->getKey()),
        );
    }
}
