<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Console;

use App\Modules\GameRecipes\Contracts\RecipeRepository;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Exceptions\RecipeRepositoryException;
use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Asks the repository how every open pull request is doing and stores the
 * answer, so the panel and the share page show "im Katalog" once a recipe was
 * merged. One failing pull request is reported and skipped; the run continues.
 * Quiet while pull requests are not configured, so the hourly schedule costs
 * nothing on an instance without a catalogue link.
 */
class SyncRecipePullRequestsCommand extends Command
{
    protected $signature = 'lanomat:recipes:pr-status
        {--json : Machine-readable output}';

    protected $description = 'Sync the state of open recipe pull requests from the git repository.';

    public function handle(RecipeRepository $repository): int
    {
        if (! $repository->isConfigured()) {
            return self::SUCCESS;
        }

        /** @var Collection<int, GameRecipe> $recipes */
        $recipes = GameRecipe::query()
            ->where('pr_state', PullRequestState::Open->value)
            ->whereNotNull('pr_number')
            ->orderBy('slug')
            ->get();

        $updated = [];
        $failed = [];
        $json = (bool) $this->option('json');

        foreach ($recipes as $recipe) {
            $number = (int) $recipe->pr_number;

            try {
                $state = $repository->state($number);
            } catch (RecipeRepositoryException $exception) {
                $failed[] = ['slug' => $recipe->slug, 'number' => $number, 'reason' => $exception->getMessage()];

                if (! $json) {
                    $this->warn(__('recipe_pull_requests.commands.sync_failed', [
                        'slug' => $recipe->slug,
                        'reason' => $exception->getMessage(),
                    ]));
                }

                continue;
            }

            $recipe->forceFill([
                'pr_state' => $state,
                'pr_synced_at' => now(),
            ])->save();

            $updated[] = ['slug' => $recipe->slug, 'number' => $number, 'state' => $state->value];
        }

        if ($json) {
            $this->line((string) json_encode([
                'checked' => $recipes->count(),
                'updated' => $updated,
                'failed' => $failed,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(__('recipe_pull_requests.commands.synced', ['count' => $recipes->count()]));

        return self::SUCCESS;
    }
}
