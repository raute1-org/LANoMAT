<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Modules\GameRecipes\Contracts\RecipeRepository;
use App\Modules\GameRecipes\Domain\PullRequestRef;
use App\Modules\GameRecipes\Domain\RecipeProposal;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Exceptions\RecipeRepositoryException;

/** Bound while recipe pull requests are off or incomplete: nothing leaves LANoMAT. */
final class NullRecipeRepository implements RecipeRepository
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function propose(RecipeProposal $proposal): PullRequestRef
    {
        throw RecipeRepositoryException::notConfigured();
    }

    public function state(int $number): PullRequestState
    {
        throw RecipeRepositoryException::notConfigured();
    }

    public function read(string $path): ?string
    {
        throw RecipeRepositoryException::notConfigured();
    }
}
