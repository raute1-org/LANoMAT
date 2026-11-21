<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Jobs;

use App\Modules\GameRecipes\Actions\ProposeRecipe;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Queues one recipe's pull request, so a participant's share never waits for
 * GitHub. Unique per recipe for a minute: sharing twice in a row proposes once.
 * Dispatched after the database transaction commits (the publish event fires
 * inside Filament's save), so the worker never reads a row the transaction has
 * not written yet. A transient repository failure is retried twice with a
 * growing pause; when the job gives up, the recipe says so (unless a pull
 * request already exists).
 *
 * A recipe that is not found yet — a contribution racing its own commit on an
 * installation that dispatches without the after-commit boundary — is released
 * for a short retry instead of being dropped; only the last attempt gives up
 * quietly.
 */
final class ProposeRecipePullRequestJob implements ShouldBeUnique, ShouldQueue, ShouldQueueAfterCommit
{
    use InteractsWithQueue;
    use Queueable;

    /** Seconds a missing recipe is retried before the next attempt. */
    private const int MISSING_RECIPE_DELAY = 15;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 60;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $recipeId) {}

    public function uniqueId(): string
    {
        return (string) $this->recipeId;
    }

    public function handle(ProposeRecipe $action): void
    {
        $recipe = GameRecipe::query()->find($this->recipeId);

        if (! $recipe instanceof GameRecipe) {
            if ($this->attempts() < $this->tries) {
                $this->release(self::MISSING_RECIPE_DELAY);
            }

            return;
        }

        if (! $action->isProposable($recipe)) {
            return;
        }

        $action->handle($recipe);
    }

    /** The last try failed: record it, but never overwrite a pull request that exists. */
    public function failed(?Throwable $exception): void
    {
        $recipe = GameRecipe::query()->find($this->recipeId);

        if ($recipe === null || $recipe->pr_number !== null) {
            return;
        }

        $recipe->forceFill([
            'pr_state' => PullRequestState::Failed,
            'pr_error' => mb_substr(
                $exception?->getMessage() ?? 'The proposal job failed.',
                0,
                ProposeRecipe::ERROR_MAX,
            ),
            'pr_synced_at' => now(),
        ])->save();
    }
}
