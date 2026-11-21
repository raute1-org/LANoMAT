<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Console;

use App\Modules\GameRecipes\Actions\ProposeRecipe;
use App\Modules\GameRecipes\Contracts\RecipeRepository;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Exceptions\RecipeRepositoryException;
use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Console\Command;

/**
 * Runs one proposal in the foreground: the orga's "propose now" from the
 * panel or a shell, and the way to see why a shared recipe was refused.
 */
class ProposeRecipeCommand extends Command
{
    protected $signature = 'lanomat:recipes:propose {slug : The recipe slug}';

    protected $description = 'Open or update the pull request that carries one recipe into the git catalogue.';

    public function handle(ProposeRecipe $propose, RecipeRepository $repository): int
    {
        $argument = $this->argument('slug');
        $slug = is_string($argument) ? $argument : '';

        $recipe = GameRecipe::query()->where('slug', $slug)->first();

        if ($recipe === null) {
            $this->error(__('recipe_pull_requests.commands.missing', ['slug' => $slug]));

            return self::FAILURE;
        }

        if (! $repository->isConfigured()) {
            $this->error(__('recipe_pull_requests.commands.not_configured'));

            return self::FAILURE;
        }

        try {
            $recipe = $propose->handle($recipe)->refresh();
        } catch (RecipeRepositoryException $exception) {
            $this->error(__('recipe_pull_requests.commands.failed', ['reason' => $exception->getMessage()]));

            return self::FAILURE;
        }

        $state = $recipe->pr_state;

        if (! $state instanceof PullRequestState) {
            $this->info(__('recipe_pull_requests.commands.nothing', ['slug' => $recipe->slug]));

            return self::SUCCESS;
        }

        if (in_array($state, [PullRequestState::Blocked, PullRequestState::Failed], true)) {
            $this->error(__('recipe_pull_requests.commands.not_sent', [
                'state' => $state->label(),
                'reason' => (string) $recipe->pr_error,
            ]));

            return self::FAILURE;
        }

        $this->info(__('recipe_pull_requests.commands.proposed', [
            'state' => $state->label(),
            'number' => (string) $recipe->pr_number,
            'url' => (string) $recipe->pr_url,
        ]));

        return self::SUCCESS;
    }
}
