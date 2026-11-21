<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Jobs;

use App\Modules\GameRecipes\Actions\ProposeRecipe;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Queues one recipe's pull request, so a participant's share never waits for
 * GitHub. Unique per recipe for a minute: sharing twice in a row proposes once.
 * A transient repository failure is retried twice with a growing pause; when
 * the job gives up, the recipe says so (unless a pull request already exists).
 */
class ProposeRecipePullRequestJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** A blocked or failed proposal keeps `pr_error`; the column is capped. */
    private const int ERROR_MAX = 2000;

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

        if ($recipe === null) {
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
                self::ERROR_MAX,
            ),
            'pr_synced_at' => now(),
        ])->save();
    }
}
