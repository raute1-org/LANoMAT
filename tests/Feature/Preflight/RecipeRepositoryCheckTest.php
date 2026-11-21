<?php

declare(strict_types=1);

use App\Modules\Preflight\Checks\RecipeRepositoryCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The catalogue link is a convenience, not a lifeline: the probe may warn,
 * never report "down", and a repository that is off is simply skipped.
 */
function recipeRepositoryConfigured(): void
{
    config([
        'recipes.pull_requests.enabled' => true,
        'recipes.pull_requests.repository' => 'lanomat/recipes',
        'recipes.pull_requests.token' => 'test-token',
        'recipes.pull_requests.api_url' => 'https://api.github.com',
    ]);
}

beforeEach(function () {
    Http::preventStrayRequests();
});

it('is skipped while recipe pull requests are not enabled', function () {
    config(['recipes.pull_requests' => ['enabled' => false]]);

    expect(app(RecipeRepositoryCheck::class)->run()->status->value)->toBe('skipped');
});

it('warns when enabled but the repository or token is missing', function () {
    config([
        'recipes.pull_requests.enabled' => true,
        'recipes.pull_requests.repository' => null,
        'recipes.pull_requests.token' => 'test-token',
    ]);

    expect(app(RecipeRepositoryCheck::class)->run()->status->value)->toBe('warn');

    config([
        'recipes.pull_requests.repository' => 'lanomat/recipes',
        'recipes.pull_requests.token' => null,
    ]);

    expect(app(RecipeRepositoryCheck::class)->run()->status->value)->toBe('warn');
});

it('is ok when the token may read and push to the repository', function () {
    recipeRepositoryConfigured();

    Http::fake([
        'api.github.com/repos/lanomat/recipes' => Http::response([
            'full_name' => 'lanomat/recipes',
            'permissions' => ['admin' => false, 'push' => true, 'pull' => true],
        ]),
    ]);

    $result = app(RecipeRepositoryCheck::class)->run();

    expect($result->status->value)->toBe('ok')
        ->and($result->message)->not->toBe('');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/lanomat/recipes'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request->hasHeader('X-GitHub-Api-Version', '2026-03-10')
        && $request->hasHeader('Accept', 'application/vnd.github+json'));
});

it('warns when the token may read but not push', function () {
    recipeRepositoryConfigured();

    Http::fake([
        'api.github.com/repos/lanomat/recipes' => Http::response([
            'permissions' => ['push' => false, 'pull' => true],
        ]),
    ]);

    $result = app(RecipeRepositoryCheck::class)->run();

    expect($result->status->value)->toBe('warn')
        ->and($result->message)->toContain('nicht schreiben');
});

it('warns with a distinct message for 401, 403 and 404', function (int $status) {
    recipeRepositoryConfigured();

    Http::fake([
        'api.github.com/repos/lanomat/recipes' => Http::response(['message' => 'nope'], $status),
    ]);

    $result = app(RecipeRepositoryCheck::class)->run();

    expect($result->status->value)->toBe('warn')
        ->and($result->message)->toContain('HTTP '.$status);
})->with([401, 403, 404]);

it('warns when the api is unreachable, and never reports down', function () {
    recipeRepositoryConfigured();

    Http::fake(fn () => throw new ConnectionException('refused'));

    $result = app(RecipeRepositoryCheck::class)->run();

    expect($result->status->value)->toBe('warn')
        ->and($result->status->value)->not->toBe('down');
});

it('warns on an unexpected status', function () {
    recipeRepositoryConfigured();

    Http::fake([
        'api.github.com/repos/lanomat/recipes' => Http::response('', 500),
    ]);

    $result = app(RecipeRepositoryCheck::class)->run();

    expect($result->status->value)->toBe('warn')
        ->and($result->message)->toContain('500');
});

it('has a German label and a stable key', function () {
    $check = app(RecipeRepositoryCheck::class);

    expect($check->key())->toBe('recipe_repository')
        ->and($check->label())->toBe('Rezept-Katalog (Pull Requests)');
});
