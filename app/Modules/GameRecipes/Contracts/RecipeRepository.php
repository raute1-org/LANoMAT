<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Contracts;

use App\Modules\GameRecipes\Domain\PullRequestRef;
use App\Modules\GameRecipes\Domain\RecipeProposal;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Exceptions\RecipeRepositoryException;

/**
 * The git repository the recipe catalogue lives in, as far as LANoMAT needs
 * it: put a recipe on a branch, keep one pull request open for it and ask how
 * that pull request is doing. The only writer of recipe text outside the
 * database; never used for game files.
 */
interface RecipeRepository
{
    /** Whether proposals can be sent at all (feature on, repository and token set). */
    public function isConfigured(): bool;

    /**
     * Put the proposal's files on its branch (created from the base branch, or
     * moved to a fresh commit on top of it) and make sure exactly one open pull
     * request carries that branch. Calling it again with changed files updates
     * the same pull request.
     *
     * @throws RecipeRepositoryException
     */
    public function propose(RecipeProposal $proposal): PullRequestRef;

    /**
     * The repository's view of a pull request opened earlier: Open, Merged or Closed.
     *
     * @throws RecipeRepositoryException
     */
    public function state(int $number): PullRequestState;

    /**
     * The text of a file on the base branch, or null when it does not exist.
     *
     * @throws RecipeRepositoryException
     */
    public function read(string $path): ?string;
}
