<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Modules\GameRecipes\Contracts\RecipeRepository;
use App\Modules\GameRecipes\Domain\PullRequestRef;
use App\Modules\GameRecipes\Domain\RecipeProposal;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Exceptions\RecipeRepositoryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * The recipe catalogue on GitHub, over the REST API with a fine-grained token
 * (contents and pull requests: write, on this one repository).
 *
 * Documented behaviour this class relies on (docs.github.com/en/rest):
 * - a tree entry may carry its `content` inline next to a `base_tree`, so a
 *   commit is built without reading or hashing blobs locally (git/trees);
 * - a ref is addressed as `heads/<branch>`; branch names may contain `/`
 *   (git/refs). A missing ref answers 404, which is how "no branch yet" is
 *   told apart from "branch exists";
 * - `PATCH /git/refs/heads/<branch>` with `force: true` rewrites the branch
 *   (git/refs);
 * - `GET /pulls?state=open&head=<owner>:<branch>&base=<base>` lists the open
 *   pull request of that head, and `GET /pulls/<number>` carries `merged`
 *   (pulls);
 * - a 403 is only a rate limit when the response says so
 *   (`x-ratelimit-remaining: 0` or a `retry-after` header); otherwise it is a
 *   plain permission error (rate-limits-for-the-rest-api).
 *
 * Requests send `Authorization: Bearer <token>`, `Accept:
 * application/vnd.github+json` (`application/vnd.github.raw+json` for reading a
 * file), `X-GitHub-Api-Version: 2026-03-10` — the current documented API
 * version, unchanged for the endpoints used here — and a `User-Agent`.
 */
final class GitHubRecipeRepository implements RecipeRepository
{
    /** GitHub REST API version spoken by this client (docs.github.com/en/rest/about-the-rest-api/api-versions). */
    private const string API_VERSION = '2026-03-10';

    private const string ACCEPT = 'application/vnd.github+json';

    /** The raw media type, the only one that returns a file's text unencoded. */
    private const string ACCEPT_RAW = 'application/vnd.github.raw+json';

    private const string USER_AGENT = 'LANoMAT-recipe-catalogue';

    /** Three attempts in total (two retries), like HttpDiscordClient. */
    private const int ATTEMPTS = 3;

    /** Fixed backoff between attempts; a rate-limited response's Retry-After wins over it. */
    private const int BACKOFF_MS = 150;

    /** A rate limit may name a long wait; the queue job has 60 s, so back off at most this long. */
    private const int MAX_BACKOFF_MS = 2000;

    /** GitHub messages are capped before they land in an exception or a recipe's `pr_error`. */
    private const int REASON_MAX = 300;

    /** Git's own limit for a branch name. */
    private const int BRANCH_MAX = 200;

    public function __construct(
        private readonly string $repository,
        private readonly string $token,
        private readonly string $base = 'main',
        private readonly string $apiUrl = 'https://api.github.com',
        private readonly int $timeout = 15,
    ) {}

    public function isConfigured(): bool
    {
        // "owner/name", with GitHub's own character rules; both parts may not
        // be empty and may not carry a path or whitespace.
        return preg_match('#^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?/[A-Za-z0-9._-]{1,100}\z#', $this->repository) === 1
            && trim($this->token) !== '';
    }

    public function propose(RecipeProposal $proposal): PullRequestRef
    {
        $this->ensureConfigured();
        $this->assertBranch($proposal->branch);
        $this->assertFiles($proposal->files);

        $owner = explode('/', $this->repository, 2)[0];

        // The base branch as it stands now: its head commit carries the tree
        // the proposal is layered on top of.
        $baseHead = $this->branchHead($this->base)
            ?? throw RecipeRepositoryException::rejected(404, "The base branch [{$this->base}] does not exist in {$this->repository}.");
        $baseTree = $this->stringAt(
            $this->getObject($this->gitPath('commits/'.$baseHead)),
            'tree.sha',
            "the tree of the base commit {$baseHead}",
        );

        $branchHead = $this->branchHead($proposal->branch);
        $tree = $this->createTree($baseTree, $proposal->files);

        // Idempotent: the branch already carries exactly this tree on top of
        // the current base head, so there is nothing to commit or move.
        if ($branchHead === null || ! $this->isUnchanged($branchHead, $baseHead, $tree)) {
            $this->commit($proposal, $baseHead, $tree, $branchHead);
        }

        $pullRequest = $this->keepPullRequestOpen($owner, $proposal);

        return new PullRequestRef(
            $pullRequest['number'],
            $pullRequest['url'],
            $proposal->branch,
            PullRequestState::Open,
        );
    }

    public function state(int $number): PullRequestState
    {
        $this->ensureConfigured();

        if ($number < 1) {
            throw RecipeRepositoryException::rejected(422, 'A pull request number must be positive.');
        }

        $pullRequest = $this->getObject($this->repoPath('/pulls/'.$number));

        if (data_get($pullRequest, 'merged') === true || is_string(data_get($pullRequest, 'merged_at'))) {
            return PullRequestState::Merged;
        }

        return data_get($pullRequest, 'state') === 'closed' ? PullRequestState::Closed : PullRequestState::Open;
    }

    public function read(string $path): ?string
    {
        $this->ensureConfigured();
        $this->assertPath($path);

        $response = $this->request(
            'GET',
            $this->contentsPath($path),
            ['ref' => $this->base],
            accept: self::ACCEPT_RAW,
            allowNotFound: true,
        );

        return $response?->body();
    }

    // -- the proposal flow --------------------------------------------------

    /** The commit a branch points at, or null when the branch does not exist yet. */
    private function branchHead(string $branch): ?string
    {
        $response = $this->request('GET', $this->gitPath('ref/'.$this->refPath($branch)), allowNotFound: true);

        if (! $response instanceof Response) {
            return null;
        }

        return $this->stringAt($response->json(), 'object.sha', "the head of the branch [{$branch}]");
    }

    /** Whether a branch head already carries the tree on top of the current base head. */
    private function isUnchanged(string $branchHead, string $baseHead, string $tree): bool
    {
        $commit = $this->getObject($this->gitPath('commits/'.$branchHead));
        $parents = data_get($commit, 'parents');

        $parent = is_array($parents) && is_array($parents[0] ?? null)
            ? ($parents[0]['sha'] ?? null)
            : null;

        return $parent === $baseHead && data_get($commit, 'tree.sha') === $tree;
    }

    /**
     * @param  array<string, string>  $files  repository-relative path => complete file contents
     */
    private function createTree(string $baseTree, array $files): string
    {
        $entries = [];

        foreach ($files as $path => $content) {
            $entries[] = [
                'path' => (string) $path,
                'mode' => '100644',
                'type' => 'blob',
                'content' => $content,
            ];
        }

        return $this->stringAt(
            $this->postObject($this->gitPath('trees'), ['base_tree' => $baseTree, 'tree' => $entries]),
            'sha',
            'the new tree',
        );
    }

    /** Creates the commit on top of the base head and creates or force-moves the branch. */
    private function commit(RecipeProposal $proposal, string $baseHead, string $tree, ?string $branchHead): void
    {
        $sha = $this->stringAt($this->postObject($this->gitPath('commits'), [
            'message' => $proposal->commitMessage,
            'tree' => $tree,
            'parents' => [$baseHead],
        ]), 'sha', 'the new commit');

        if ($branchHead === null) {
            $this->postObject($this->gitPath('refs'), [
                'ref' => 'refs/heads/'.$proposal->branch,
                'sha' => $sha,
            ]);

            return;
        }

        // A brand-new commit almost never fast-forwards the old branch head,
        // so the ref is always moved with force.
        $this->patchObject($this->gitPath('refs/'.$this->refPath($proposal->branch)), [
            'sha' => $sha,
            'force' => true,
        ]);
    }

    /**
     * The one open pull request for this branch, created when there is none
     * and updated to the proposal's title and body when there is.
     *
     * @return array{number: int, url: string}
     */
    private function keepPullRequestOpen(string $owner, RecipeProposal $proposal): array
    {
        $head = $owner.':'.$proposal->branch;

        foreach ($this->getList($this->repoPath('/pulls'), ['state' => 'open', 'head' => $head, 'base' => $this->base]) as $row) {
            $number = $row['number'] ?? null;

            if (data_get($row, 'state') !== 'open'
                || data_get($row, 'head.ref') !== $proposal->branch
                || ! is_numeric($number)) {
                continue;
            }

            $number = (int) $number;
            $updated = $this->patchObject($this->repoPath('/pulls/'.$number), [
                'title' => $proposal->title,
                'body' => $proposal->body,
            ]);

            return ['number' => $number, 'url' => $this->pullRequestUrl($updated, $row, $number)];
        }

        $created = $this->postObject($this->repoPath('/pulls'), [
            'title' => $proposal->title,
            'body' => $proposal->body,
            'head' => $head,
            'base' => $this->base,
        ]);
        $number = data_get($created, 'number');

        if (! is_numeric($number)) {
            throw RecipeRepositoryException::unreachable('GitHub answered without the number of the new pull request.');
        }

        return [
            'number' => (int) $number,
            'url' => $this->pullRequestUrl($created, [], (int) $number),
        ];
    }

    // -- validation ---------------------------------------------------------

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw RecipeRepositoryException::notConfigured();
        }
    }

    private function assertBranch(string $branch): void
    {
        if ($branch === '' || mb_strlen($branch) > self::BRANCH_MAX) {
            $this->reject(sprintf('A branch name must be 1 to %d characters long (got [%s]).', self::BRANCH_MAX, $this->oneLine($branch)));
        }

        if (preg_match('#^[A-Za-z0-9._/-]+$#', $branch) !== 1) {
            $this->reject("A branch name may only use letters, digits, '.', '_', '-' and '/' (got [{$this->oneLine($branch)}]).");
        }

        if (str_starts_with($branch, '/') || str_ends_with($branch, '/') || str_contains($branch, '//')) {
            $this->reject("A branch name may not start or end with '/' or contain an empty segment (got [{$this->oneLine($branch)}]).");
        }

        if (str_contains($branch, '..')) {
            $this->reject("A branch name may not contain '..' (got [{$this->oneLine($branch)}]).");
        }

        // Git refuses a ref ending in a dot or .lock; failing here beats a 422 later.
        if (str_ends_with($branch, '.') || str_ends_with($branch, '.lock')) {
            $this->reject("A branch name may not end with '.' or '.lock' (got [{$this->oneLine($branch)}]).");
        }

        if ($branch === $this->base) {
            $this->reject("A proposal branch may not be the base branch [{$this->oneLine($this->base)}].");
        }
    }

    /**
     * @param  array<string, string>  $files
     */
    private function assertFiles(array $files): void
    {
        if ($files === []) {
            $this->reject('A proposal needs at least one file.');
        }

        foreach (array_keys($files) as $path) {
            $this->assertPath((string) $path);
        }
    }

    private function assertPath(string $path): void
    {
        if ($path === '') {
            $this->reject('A file path must not be empty.');
        }

        if (str_contains($path, '\\')) {
            $this->reject("A file path uses '/' as separator, not '\\' (got [{$this->oneLine($path)}]).");
        }

        if (str_starts_with($path, '/')) {
            $this->reject("A file path must be relative to the repository, not absolute (got [{$this->oneLine($path)}]).");
        }

        if (str_ends_with($path, '/')) {
            $this->reject("A file path must name a file, not a directory (got [{$this->oneLine($path)}]).");
        }

        $segments = explode('/', $path);

        if (in_array('', $segments, true)) {
            $this->reject("A file path may not contain an empty segment (got [{$this->oneLine($path)}]).");
        }

        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            $this->reject("A file path may not contain '.' or '..' segments (got [{$this->oneLine($path)}]).");
        }
    }

    private function reject(string $reason): never
    {
        throw RecipeRepositoryException::rejected(422, $this->oneLine($reason));
    }

    // -- HTTP ---------------------------------------------------------------

    /**
     * One GitHub call, retried on transient failures only.
     *
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>|null  $payload
     * @return Response|null null only for a 404 when the caller allows it
     */
    private function request(
        string $method,
        string $path,
        array $query = [],
        ?array $payload = null,
        string $accept = self::ACCEPT,
        bool $allowNotFound = false,
    ): ?Response {
        $http = $this->http($accept);
        $url = $this->url($path, $query);

        try {
            $response = match ($method) {
                'GET' => $http->get($url),
                'POST' => $http->post($url, $payload ?? []),
                'PATCH' => $http->patch($url, $payload ?? []),
                default => throw new LogicException("Unsupported HTTP method [{$method}]."),
            };
        } catch (ConnectionException $exception) {
            throw RecipeRepositoryException::unreachable(
                $this->oneLine($exception->getMessage() !== '' ? $exception->getMessage() : 'the connection failed'),
            );
        }

        if ($allowNotFound && $response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw $this->failure($response);
        }

        return $response;
    }

    private function http(string $accept): PendingRequest
    {
        return Http::withToken($this->token)
            ->accept($accept)
            ->withHeaders([
                'User-Agent' => self::USER_AGENT,
                'X-GitHub-Api-Version' => self::API_VERSION,
            ])
            ->timeout($this->timeout)
            ->retry(
                self::ATTEMPTS,
                fn (int $attempt, Throwable $exception): int => $this->retryDelayMilliseconds($exception),
                when: fn (Throwable $exception): bool => $this->isTransient($exception),
                throw: false,
            );
    }

    /**
     * Only retry what can succeed later: connection errors, 5xx and a rate
     * limit. A 401/404/422 is a permanent answer and is surfaced at once.
     */
    private function isTransient(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            return $exception->response->status() >= 500 || $this->isRateLimited($exception->response);
        }

        return false;
    }

    /**
     * GitHub answers a rate limit with 429, or with 403 plus either
     * `x-ratelimit-remaining: 0` (primary) or a `retry-after` header
     * (secondary). A 403 without them is a permission problem.
     */
    private function isRateLimited(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }

        if ($response->status() !== 403) {
            return false;
        }

        return $response->header('Retry-After') !== ''
            || $response->header('X-RateLimit-Remaining') === '0';
    }

    private function retryDelayMilliseconds(Throwable $exception): int
    {
        if ($exception instanceof RequestException && $this->isRateLimited($exception->response)) {
            $retryAfter = $exception->response->header('Retry-After');

            if (is_numeric($retryAfter)) {
                return (int) min(self::MAX_BACKOFF_MS, max(0, round(((float) $retryAfter) * 1000)));
            }
        }

        return self::BACKOFF_MS;
    }

    private function failure(Response $response): RecipeRepositoryException
    {
        $status = $response->status();
        $reason = $this->reason($response);

        if ($this->isRateLimited($response)) {
            return RecipeRepositoryException::unreachable("GitHub rate limit (HTTP {$status}): {$reason}");
        }

        if ($status >= 500) {
            return RecipeRepositoryException::unreachable("HTTP {$status}: {$reason}");
        }

        return RecipeRepositoryException::rejected($status, $reason);
    }

    /** GitHub's own message, plus its `errors` detail, on one capped line. */
    private function reason(Response $response): string
    {
        $message = $response->json('message');
        $reason = is_string($message) && trim($message) !== '' ? $message : $response->body();

        $errors = $response->json('errors');

        if (is_array($errors) && $errors !== []) {
            $detail = json_encode($errors);

            if (is_string($detail)) {
                $reason .= ' '.$detail;
            }
        }

        $reason = $this->oneLine($reason);

        return $reason === '' ? 'no message' : $reason;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $fallback
     */
    private function pullRequestUrl(array $data, array $fallback, int $number): string
    {
        $url = data_get($data, 'html_url') ?? data_get($fallback, 'html_url');

        if (! is_string($url) || $url === '') {
            throw RecipeRepositoryException::unreachable("GitHub answered without the URL of pull request #{$number}.");
        }

        return $url;
    }

    /** A nested string of a decoded GitHub answer, e.g. `object.sha`. */
    private function stringAt(mixed $data, string $key, string $what): string
    {
        $value = data_get($data, $key);

        if (! is_string($value) || $value === '') {
            throw RecipeRepositoryException::unreachable("GitHub answered without {$what}.");
        }

        return $value;
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    private function getObject(string $path, array $query = []): array
    {
        return $this->asObject($this->expect($this->request('GET', $path, $query)), $path);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    private function getList(string $path, array $query = []): array
    {
        $data = $this->expect($this->request('GET', $path, $query))->json();

        if (! is_array($data)) {
            throw RecipeRepositoryException::unreachable("GitHub answered {$path} with an unexpected shape.");
        }

        $rows = [];

        foreach ($data as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postObject(string $path, array $payload): array
    {
        return $this->asObject($this->expect($this->request('POST', $path, payload: $payload)), $path);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function patchObject(string $path, array $payload): array
    {
        return $this->asObject($this->expect($this->request('PATCH', $path, payload: $payload)), $path);
    }

    private function expect(?Response $response): Response
    {
        return $response ?? throw RecipeRepositoryException::unreachable('GitHub answered without a response body.');
    }

    /**
     * @return array<string, mixed>
     */
    private function asObject(Response $response, string $path): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw RecipeRepositoryException::unreachable("GitHub answered {$path} with an unexpected shape.");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    // -- URLs ---------------------------------------------------------------

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function url(string $path, array $query = []): string
    {
        $url = rtrim($this->apiUrl, '/').$path;

        return $query === [] ? $url : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function repoPath(string $suffix): string
    {
        return '/repos/'.$this->encodeSegments($this->repository).$suffix;
    }

    private function gitPath(string $suffix): string
    {
        return $this->repoPath('/git/'.$suffix);
    }

    private function contentsPath(string $path): string
    {
        return $this->repoPath('/contents/'.$this->encodeSegments($path));
    }

    /** A ref addressed as `heads/<branch>`; a branch's slashes stay separators. */
    private function refPath(string $branch): string
    {
        return 'heads/'.$this->encodeSegments($branch);
    }

    private function encodeSegments(string $value): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $value)));
    }

    // -- text ---------------------------------------------------------------

    /** External text on one line, without the token, capped for a log or a flash message. */
    private function oneLine(string $text): string
    {
        $token = trim($this->token);
        $line = $token === '' ? $text : str_replace($token, '***', $text);
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;

        return Str::limit(trim($line), self::REASON_MAX);
    }
}
