<?php

declare(strict_types=1);

use App\Modules\GameRecipes\Domain\RecipeProposal;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Exceptions\RecipeRepositoryException;
use App\Modules\GameRecipes\Support\GitHubRecipeRepository;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * The client under test. `lanomat/recipes` is an invented repository; no test
 * reaches the real API (every request is faked).
 */
function githubRecipeRepository(string $repository = 'lanomat/recipes', string $token = 'ghp-secret-token'): GitHubRecipeRepository
{
    return new GitHubRecipeRepository($repository, $token);
}

function githubRecipeUrl(string $suffix = ''): string
{
    return 'https://api.github.com/repos/lanomat/recipes'.$suffix;
}

/**
 * @param  array<string, string>|null  $files
 */
function githubRecipeProposal(
    string $branch = 'recipes/tiny-racer',
    string $title = 'Add Tiny Racer',
    string $body = 'Body text',
    ?array $files = null,
): RecipeProposal {
    return new RecipeProposal(
        'tiny-racer',
        $branch,
        'feat(recipes): add Tiny Racer',
        $title,
        $body,
        $files ?? [
            'resources/recipes/tiny-racer/recipe.yaml' => "slug: tiny-racer\ntitle: Tiny Racer\n",
            'resources/recipes/tiny-racer/SKILL.md' => "# Tiny Racer\n",
        ],
    );
}

/**
 * The GitHub answers of a first proposal: base branch, no proposal branch,
 * the three write steps and the pull request.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function githubRecipeFake(array $overrides = []): array
{
    return array_merge([
        // Longest patterns first: the fake matches in insertion order.
        'api.github.com/repos/lanomat/recipes/git/ref/heads/main' => Http::response(['ref' => 'refs/heads/main', 'object' => ['sha' => 'base-head']], 200),
        'api.github.com/repos/lanomat/recipes/git/commits/base-head' => Http::response(['sha' => 'base-head', 'tree' => ['sha' => 'base-tree'], 'parents' => [['sha' => 'older-head']]], 200),
        'api.github.com/repos/lanomat/recipes/git/ref/heads/recipes/tiny-racer' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/repos/lanomat/recipes/git/trees' => Http::response(['sha' => 'proposal-tree'], 201),
        'api.github.com/repos/lanomat/recipes/git/commits' => Http::response(['sha' => 'proposal-commit'], 201),
        'api.github.com/repos/lanomat/recipes/git/refs' => Http::response(['ref' => 'refs/heads/recipes/tiny-racer', 'object' => ['sha' => 'proposal-commit']], 201),
        'api.github.com/repos/lanomat/recipes/pulls?*' => Http::response([], 200),
        'api.github.com/repos/lanomat/recipes/pulls/*' => Http::response(['number' => 7, 'state' => 'open', 'html_url' => 'https://github.test/lanomat/pull/7'], 200),
        'api.github.com/repos/lanomat/recipes/pulls' => Http::response(['number' => 7, 'state' => 'open', 'html_url' => 'https://github.test/lanomat/pull/7'], 201),
    ], $overrides);
}

/** The open pull request the catalogue already carries for the proposal branch. */
function githubRecipeExistingPullRequest(): PromiseInterface
{
    return Http::response([[
        'number' => 7,
        'state' => 'open',
        'html_url' => 'https://github.test/lanomat/pull/7',
        'head' => ['ref' => 'recipes/tiny-racer', 'sha' => 'proposal-commit'],
        'base' => ['ref' => 'main'],
    ]], 200);
}

/** @return list<string> "METHOD url" of every recorded request, in call order */
function githubRecipeCalls(): array
{
    $calls = [];

    /** @var array{0: Request, 1: Response} $pair */
    foreach (Http::recorded() as $pair) {
        $calls[] = $pair[0]->method().' '.$pair[0]->url();
    }

    return $calls;
}

/** Runs the call and hands back the failure it must produce. */
function githubRecipeFailure(callable $call): RecipeRepositoryException
{
    try {
        $call();
    } catch (RecipeRepositoryException $exception) {
        return $exception;
    }

    throw new RuntimeException('The repository call was expected to fail.');
}

describe('propose', function () {
    it('puts the files on a new branch and opens one pull request', function () {
        Http::fake(githubRecipeFake());

        $pullRequest = githubRecipeRepository()->propose(githubRecipeProposal());

        expect($pullRequest->number)->toBe(7)
            ->and($pullRequest->url)->toBe('https://github.test/lanomat/pull/7')
            ->and($pullRequest->branch)->toBe('recipes/tiny-racer')
            ->and($pullRequest->state)->toBe(PullRequestState::Open);

        // The documented flow, one call per step: base ref, base commit's
        // tree, the proposal branch, the new tree, the commit, the ref, the
        // open pull request, the new pull request.
        expect(githubRecipeCalls())->toBe([
            'GET '.githubRecipeUrl('/git/ref/heads/main'),
            'GET '.githubRecipeUrl('/git/commits/base-head'),
            'GET '.githubRecipeUrl('/git/ref/heads/recipes/tiny-racer'),
            'POST '.githubRecipeUrl('/git/trees'),
            'POST '.githubRecipeUrl('/git/commits'),
            'POST '.githubRecipeUrl('/git/refs'),
            'GET '.githubRecipeUrl('/pulls').'?state=open&head=lanomat%3Arecipes%2Ftiny-racer&base=main',
            'POST '.githubRecipeUrl('/pulls'),
        ]);
    });

    it('layers the tree on the base tree with the file contents inline', function () {
        Http::fake(githubRecipeFake());

        githubRecipeRepository()->propose(githubRecipeProposal());

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === githubRecipeUrl('/git/trees')
            && $request['base_tree'] === 'base-tree'
            && $request['tree'] === [
                [
                    'path' => 'resources/recipes/tiny-racer/recipe.yaml',
                    'mode' => '100644',
                    'type' => 'blob',
                    'content' => "slug: tiny-racer\ntitle: Tiny Racer\n",
                ],
                [
                    'path' => 'resources/recipes/tiny-racer/SKILL.md',
                    'mode' => '100644',
                    'type' => 'blob',
                    'content' => "# Tiny Racer\n",
                ],
            ]);
    });

    it('commits on top of the base head and creates the branch ref', function () {
        Http::fake(githubRecipeFake());

        githubRecipeRepository()->propose(githubRecipeProposal());

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === githubRecipeUrl('/git/commits')
            && $request['message'] === 'feat(recipes): add Tiny Racer'
            && $request['tree'] === 'proposal-tree'
            && $request['parents'] === ['base-head']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === githubRecipeUrl('/git/refs')
            && $request['ref'] === 'refs/heads/recipes/tiny-racer'
            && $request['sha'] === 'proposal-commit');
    });

    it('opens the pull request against the base branch with the owner-qualified head', function () {
        Http::fake(githubRecipeFake());

        githubRecipeRepository()->propose(githubRecipeProposal());

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === githubRecipeUrl('/pulls')
            && $request['title'] === 'Add Tiny Racer'
            && $request['body'] === 'Body text'
            && $request['head'] === 'lanomat:recipes/tiny-racer'
            && $request['base'] === 'main');
    });

    it('sends the documented headers with the token on every request', function () {
        Http::fake(githubRecipeFake());

        githubRecipeRepository()->propose(githubRecipeProposal());

        $sent = Http::recorded();

        expect($sent)->toHaveCount(8);

        /** @var array{0: Request, 1: Response} $pair */
        foreach ($sent as $pair) {
            expect($pair[0]->hasHeader('Authorization', 'Bearer ghp-secret-token'))->toBeTrue()
                ->and($pair[0]->hasHeader('Accept', 'application/vnd.github+json'))->toBeTrue()
                ->and($pair[0]->hasHeader('X-GitHub-Api-Version', '2026-03-10'))->toBeTrue()
                ->and($pair[0]->header('User-Agent')[0])->toContain('LANoMAT');
        }
    });

    it('moves the existing branch and updates the same pull request', function () {
        Http::fake(githubRecipeFake([
            'api.github.com/repos/lanomat/recipes/git/ref/heads/recipes/tiny-racer' => Http::response(['ref' => 'refs/heads/recipes/tiny-racer', 'object' => ['sha' => 'old-commit']], 200),
            'api.github.com/repos/lanomat/recipes/git/commits/old-commit' => Http::response(['sha' => 'old-commit', 'tree' => ['sha' => 'old-tree'], 'parents' => [['sha' => 'base-head']]], 200),
            'api.github.com/repos/lanomat/recipes/git/trees' => Http::response(['sha' => 'changed-tree'], 201),
            'api.github.com/repos/lanomat/recipes/git/commits' => Http::response(['sha' => 'changed-commit'], 201),
            'api.github.com/repos/lanomat/recipes/git/refs/heads/recipes/tiny-racer' => Http::response(['ref' => 'refs/heads/recipes/tiny-racer', 'object' => ['sha' => 'changed-commit']], 200),
            'api.github.com/repos/lanomat/recipes/pulls?*' => githubRecipeExistingPullRequest(),
        ]));

        $pullRequest = githubRecipeRepository()->propose(githubRecipeProposal(title: 'Add Tiny Racer (v2)', body: 'Updated body.'));

        expect($pullRequest->number)->toBe(7);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === githubRecipeUrl('/git/commits')
            && $request['tree'] === 'changed-tree'
            && $request['parents'] === ['base-head']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === githubRecipeUrl('/git/refs/heads/recipes/tiny-racer')
            && $request['sha'] === 'changed-commit'
            && $request['force'] === true);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === githubRecipeUrl('/pulls/7')
            && $request['title'] === 'Add Tiny Racer (v2)'
            && $request['body'] === 'Updated body.');

        // Never a second pull request for the same branch.
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === githubRecipeUrl('/pulls'));
    });

    it('writes nothing when the branch already carries the proposal on the base head', function () {
        Http::fake(githubRecipeFake([
            'api.github.com/repos/lanomat/recipes/git/ref/heads/recipes/tiny-racer' => Http::response(['ref' => 'refs/heads/recipes/tiny-racer', 'object' => ['sha' => 'proposal-commit']], 200),
            'api.github.com/repos/lanomat/recipes/git/commits/proposal-commit' => Http::response(['sha' => 'proposal-commit', 'tree' => ['sha' => 'proposal-tree'], 'parents' => [['sha' => 'base-head']]], 200),
            'api.github.com/repos/lanomat/recipes/pulls?*' => githubRecipeExistingPullRequest(),
        ]));

        $pullRequest = githubRecipeRepository()->propose(githubRecipeProposal());

        expect($pullRequest->number)->toBe(7)
            ->and($pullRequest->state)->toBe(PullRequestState::Open);

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === githubRecipeUrl('/git/commits'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === githubRecipeUrl('/git/refs'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && str_starts_with($request->url(), githubRecipeUrl('/git/refs')));

        // The pull request's title and body are still kept current.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === githubRecipeUrl('/pulls/7')
            && $request['title'] === 'Add Tiny Racer');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === githubRecipeUrl('/pulls'));
    });

    it('refuses a proposal for the base branch, an unsafe branch name or an unsafe path without a request', function (string $branch, string $expected) {
        Http::fake();

        $failure = githubRecipeFailure(fn () => githubRecipeRepository()->propose(githubRecipeProposal(branch: $branch)));

        expect($failure->transient)->toBeFalse()
            ->and($failure->getMessage())->toContain('(422)')
            ->and($failure->getMessage())->toContain($expected);

        Http::assertNothingSent();
    })->with([
        'base branch' => ['main', 'may not be the base branch'],
        'parent segment' => ['recipes/../escape', "may not contain '..'"],
        'leading slash' => ['/recipes/tiny-racer', "may not start or end with '/'"],
        'trailing slash' => ['recipes/tiny-racer/', "may not start or end with '/'"],
        'empty segment' => ['recipes//tiny-racer', 'empty segment'],
        'space' => ['recipes/tiny racer', 'may only use letters'],
        'lock suffix' => ['recipes/tiny-racer.lock', "may not end with '.' or '.lock'"],
        'dot suffix' => ['recipes/tiny-racer.', "may not end with '.' or '.lock'"],
        'empty' => ['', 'must be 1 to 200 characters'],
        'too long' => [str_repeat('a', 201), 'must be 1 to 200 characters'],
    ]);

    it('refuses a proposal without files and one with an unsafe path without a request', function (array $files, string $expected) {
        Http::fake();

        $failure = githubRecipeFailure(fn () => githubRecipeRepository()->propose(githubRecipeProposal(files: $files)));

        expect($failure->transient)->toBeFalse()
            ->and($failure->getMessage())->toContain('(422)')
            ->and($failure->getMessage())->toContain($expected);

        Http::assertNothingSent();
    })->with([
        'no files' => [[], 'at least one file'],
        'absolute path' => [['/resources/recipes/tiny-racer/recipe.yaml' => 'x'], 'must be relative'],
        'parent segment' => [['resources/../recipe.yaml' => 'x'], "may not contain '.' or '..'"],
        'empty segment' => [['resources//recipe.yaml' => 'x'], 'empty segment'],
        'directory' => [['resources/recipes/tiny-racer/' => 'x'], 'must name a file'],
        'backslash' => [['resources\\recipe.yaml' => 'x'], "uses '/' as separator"],
        'empty path' => [['' => 'x'], 'must not be empty'],
    ]);
});

describe('state', function () {
    it('reports an open pull request', function () {
        Http::fake(['api.github.com/repos/lanomat/recipes/pulls/7' => Http::response(['number' => 7, 'state' => 'open', 'merged' => false, 'merged_at' => null], 200)]);

        expect(githubRecipeRepository()->state(7))->toBe(PullRequestState::Open);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === githubRecipeUrl('/pulls/7'));
    });

    it('reports a closed pull request', function () {
        Http::fake(['api.github.com/repos/lanomat/recipes/pulls/7' => Http::response(['number' => 7, 'state' => 'closed', 'merged' => false, 'merged_at' => null], 200)]);

        expect(githubRecipeRepository()->state(7))->toBe(PullRequestState::Closed);
    });

    it('reports a merged pull request', function () {
        Http::fake(['api.github.com/repos/lanomat/recipes/pulls/7' => Http::response(['number' => 7, 'state' => 'closed', 'merged' => true, 'merged_at' => '2026-11-21T10:00:00Z'], 200)]);

        expect(githubRecipeRepository()->state(7))->toBe(PullRequestState::Merged);
    });

    it('reports a merged pull request whose merged flag is missing but whose merge date is set', function () {
        Http::fake(['api.github.com/repos/lanomat/recipes/pulls/7' => Http::response(['number' => 7, 'state' => 'closed', 'merged_at' => '2026-11-21T10:00:00Z'], 200)]);

        expect(githubRecipeRepository()->state(7))->toBe(PullRequestState::Merged);
    });
});

describe('read', function () {
    it('reads a file of the base branch as raw text', function () {
        Http::fake([
            'api.github.com/repos/lanomat/recipes/contents/resources/recipes/tiny-racer/recipe.yaml*' => Http::response("slug: tiny-racer\n", 200, ['Content-Type' => 'application/vnd.github.raw+json']),
        ]);

        $text = githubRecipeRepository()->read('resources/recipes/tiny-racer/recipe.yaml');

        expect($text)->toBe("slug: tiny-racer\n");

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === githubRecipeUrl('/contents/resources/recipes/tiny-racer/recipe.yaml').'?ref=main'
            && $request->hasHeader('Accept', 'application/vnd.github.raw+json'));
    });

    it('returns null for a file that does not exist', function () {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        expect(githubRecipeRepository()->read('resources/recipes/gone/recipe.yaml'))->toBeNull();

        Http::assertSentCount(1);
    });

    it('refuses an unsafe path without a request', function (string $path, string $expected) {
        Http::fake();

        $failure = githubRecipeFailure(fn () => githubRecipeRepository()->read($path));

        expect($failure->transient)->toBeFalse()
            ->and($failure->getMessage())->toContain('(422)')
            ->and($failure->getMessage())->toContain($expected);

        Http::assertNothingSent();
    })->with([
        'empty' => ['', 'must not be empty'],
        'absolute' => ['/resources/recipes/x/recipe.yaml', 'must be relative'],
        'parent' => ['resources/recipes/../../x.yaml', "may not contain '.' or '..'"],
        'backslash' => ['resources\\recipes\\x.yaml', "uses '/' as separator"],
        'directory' => ['resources/recipes/x/', 'must name a file'],
    ]);
});

describe('failures', function () {
    it('maps a failed answer to the right exception class', function (int $status, ?string $retryAfter, ?string $rateRemaining, bool $transient) {
        $headers = [];

        if ($retryAfter !== null) {
            $headers['Retry-After'] = $retryAfter;
        }

        if ($rateRemaining !== null) {
            $headers['X-RateLimit-Remaining'] = $rateRemaining;
        }

        Http::fake(['api.github.com/*' => Http::response(['message' => "GitHub said no\non two lines"], $status, $headers)]);

        $failure = githubRecipeFailure(fn () => githubRecipeRepository()->state(7));

        expect($failure->transient)->toBe($transient)
            ->and($failure->getMessage())->toContain('GitHub said no on two lines');
    })->with([
        'unauthorized' => [401, null, null, false],
        'not found' => [404, null, null, false],
        'validation failed' => [422, null, null, false],
        'plain forbidden' => [403, null, '42', false],
        'primary rate limit behind a 403' => [403, null, '0', true],
        'secondary rate limit behind a 403' => [403, '0', null, true],
        'rate limited with 429' => [429, '0', null, true],
        'server error' => [500, null, null, true],
        'service unavailable' => [503, null, null, true],
    ]);

    it('does not retry a permanent client error', function (int $status) {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'nope'], $status)]);

        githubRecipeFailure(fn () => githubRecipeRepository()->state(7));

        Http::assertSentCount(1);
    })->with([401, 403, 404, 422]);

    it('retries a transient server error and then succeeds', function () {
        Http::fake([
            'api.github.com/repos/lanomat/recipes/pulls/7' => Http::sequence()
                ->push(['message' => 'Internal Server Error'], 500)
                ->push(['number' => 7, 'state' => 'open', 'merged' => false], 200),
        ]);

        expect(githubRecipeRepository()->state(7))->toBe(PullRequestState::Open);

        Http::assertSentCount(2);
    });

    it('gives up after three attempts against a persistent server error', function () {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Internal Server Error'], 500)]);

        $failure = githubRecipeFailure(fn () => githubRecipeRepository()->state(7));

        expect($failure->transient)->toBeTrue()
            ->and($failure->getMessage())->toContain('HTTP 500');

        Http::assertSentCount(3);
    });

    it('reports a connection failure as transient and retries it', function () {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: timed out');
        });

        $failure = githubRecipeFailure(fn () => githubRecipeRepository()->propose(githubRecipeProposal()));

        expect($failure->transient)->toBeTrue()
            ->and($failure->getMessage())->toContain('not reachable')
            ->and($attempts)->toBe(3);
    });

    it('never lets the token reach the message or the string form of the failure', function () {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials for ghp-secret-token'], 401)]);

        $failure = githubRecipeFailure(fn () => githubRecipeRepository()->state(7));

        expect($failure->getMessage())->toContain('***')
            ->and($failure->getMessage())->not->toContain('ghp-secret-token')
            ->and((string) $failure)->not->toContain('ghp-secret-token');
    });

    it('refuses to send when it is not configured', function () {
        Http::fake();

        $failure = githubRecipeFailure(fn () => (new GitHubRecipeRepository('', ''))->propose(githubRecipeProposal()));

        expect($failure->transient)->toBeFalse()
            ->and($failure->getMessage())->toBe('Recipe pull requests are not configured.');

        Http::assertNothingSent();
    });

    it('reports a missing base branch as a rejected request instead of creating a branch', function () {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $failure = githubRecipeFailure(fn () => githubRecipeRepository()->propose(githubRecipeProposal()));

        expect($failure->transient)->toBeFalse()
            ->and($failure->getMessage())->toContain('(404)')
            ->and($failure->getMessage())->toContain('The base branch [main] does not exist in lanomat/recipes.');

        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    });
});

describe('configuration', function () {
    it('is configured only with an owner/name repository and a token', function (string $repository, string $token, bool $expected) {
        expect((new GitHubRecipeRepository($repository, $token))->isConfigured())->toBe($expected);
    })->with([
        'good' => ['lanomat/recipes', 'ghp-token', true],
        'hyphenated names' => ['lan-mat/recipe-catalogue', 'ghp-token', true],
        'dots in the name' => ['lanomat/recipe.catalogue', 'ghp-token', true],
        'empty repository' => ['', 'ghp-token', false],
        'no owner' => ['recipes', 'ghp-token', false],
        'too many segments' => ['lanomat/recipes/extra', 'ghp-token', false],
        'leading space' => [' lanomat/recipes', 'ghp-token', false],
        'trailing newline' => ["lanomat/recipes\n", 'ghp-token', false],
        'a URL' => ['https://github.test/lanomat/recipes', 'ghp-token', false],
        'empty token' => ['lanomat/recipes', '', false],
        'blank token' => ['lanomat/recipes', '   ', false],
    ]);
});
