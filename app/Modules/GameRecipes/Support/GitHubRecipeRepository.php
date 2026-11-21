<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Modules\GameRecipes\Contracts\RecipeRepository;
use App\Modules\GameRecipes\Domain\PullRequestRef;
use App\Modules\GameRecipes\Domain\RecipeProposal;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Exceptions\RecipeRepositoryException;

/**
 * The recipe catalogue on GitHub, over the REST API with a fine-grained token
 * (contents and pull requests: write, on this one repository).
 */
final class GitHubRecipeRepository implements RecipeRepository
{
    public function __construct(
        private readonly string $repository,
        private readonly string $token,
        private readonly string $base = 'main',
        private readonly string $apiUrl = 'https://api.github.com',
        private readonly int $timeout = 15,
    ) {}

    public function isConfigured(): bool
    {
        return $this->repository !== '' && $this->token !== '';
    }

    public function propose(RecipeProposal $proposal): PullRequestRef
    {
        throw RecipeRepositoryException::unreachable("not implemented for {$this->apiUrl} ({$this->base}, {$this->timeout}s)");
    }

    public function state(int $number): PullRequestState
    {
        throw RecipeRepositoryException::unreachable('not implemented');
    }

    public function read(string $path): ?string
    {
        throw RecipeRepositoryException::unreachable('not implemented');
    }
}
