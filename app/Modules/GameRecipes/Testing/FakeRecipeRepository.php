<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Testing;

use App\Modules\GameRecipes\Contracts\RecipeRepository;
use App\Modules\GameRecipes\Domain\PullRequestRef;
use App\Modules\GameRecipes\Domain\RecipeProposal;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Exceptions\RecipeRepositoryException;
use PHPUnit\Framework\Assert;

/**
 * In-memory recipe repository for tests. One branch keeps one pull request
 * number, like the real repository; `states` and `files` are plain arrays a
 * test may set up or change between calls.
 */
final class FakeRecipeRepository implements RecipeRepository
{
    public bool $configured = true;

    /** @var list<RecipeProposal> every proposal in call order */
    public array $proposals = [];

    /** @var array<int, PullRequestState> pull request number => state */
    public array $states = [];

    /** @var array<string, string> base-branch files by path */
    public array $files = [];

    public ?RecipeRepositoryException $failure = null;

    /** @var array<string, int> branch => pull request number */
    private array $numbers = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function propose(RecipeProposal $proposal): PullRequestRef
    {
        $this->guard();

        $this->proposals[] = $proposal;
        $number = $this->numbers[$proposal->branch] ??= count($this->numbers) + 1;
        $this->states[$number] = PullRequestState::Open;

        return new PullRequestRef(
            $number,
            'https://github.test/lanomat/pull/'.$number,
            $proposal->branch,
            PullRequestState::Open,
        );
    }

    public function state(int $number): PullRequestState
    {
        $this->guard();

        return $this->states[$number] ?? throw RecipeRepositoryException::rejected(404, 'Not Found');
    }

    public function read(string $path): ?string
    {
        $this->guard();

        return $this->files[$path] ?? null;
    }

    public function failWith(?RecipeRepositoryException $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function lastProposal(): ?RecipeProposal
    {
        return $this->proposals === [] ? null : $this->proposals[count($this->proposals) - 1];
    }

    public function assertProposed(string $slug): void
    {
        Assert::assertContains(
            $slug,
            array_map(static fn (RecipeProposal $proposal): string => $proposal->slug, $this->proposals),
            "No pull request was proposed for [{$slug}].",
        );
    }

    public function assertNothingProposed(): void
    {
        Assert::assertSame([], $this->proposals, 'A pull request was proposed.');
    }

    private function guard(): void
    {
        if (! $this->configured) {
            throw RecipeRepositoryException::notConfigured();
        }

        if ($this->failure instanceof RecipeRepositoryException) {
            throw $this->failure;
        }
    }
}
