<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Exceptions\RecipeRepositoryException;
use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/**
 * The two pull request commands: the foreground "propose now" and the hourly
 * state sync. Both are quiet while pull requests are off, and a broken pull
 * request never stops the batch.
 */
function commandTrigger(): void
{
    config([
        'recipes.pull_requests.enabled' => true,
        'recipes.pull_requests.trigger' => 'contribute',
        'recipes.pull_requests.attribution' => false,
        'recipes.pull_requests.branch_prefix' => 'recipes/',
        'recipes.pull_requests.path' => 'resources/recipes',
    ]);
}

function recipeWithPullRequest(string $slug, int $number, PullRequestState $state = PullRequestState::Open): GameRecipe
{
    $recipe = GameRecipe::factory()->create(['slug' => $slug]);
    $recipe->forceFill([
        'pr_number' => $number,
        'pr_url' => 'https://github.test/lanomat/pull/'.$number,
        'pr_branch' => 'recipes/'.$slug,
        'pr_state' => $state,
        'pr_synced_at' => now()->subDay()->startOfSecond(),
    ])->save();

    return $recipe->fresh() ?? $recipe;
}

it('maps open, merged and closed pull requests back into the recipe', function () {
    commandTrigger();
    $fake = fakeRecipeRepository();

    $merged = recipeWithPullRequest('merged-game', 1);
    $closed = recipeWithPullRequest('closed-game', 2);
    $stillOpen = recipeWithPullRequest('open-game', 3);
    recipeWithPullRequest('done-game', 4, PullRequestState::Closed);

    $fake->states = [
        1 => PullRequestState::Merged,
        2 => PullRequestState::Closed,
        3 => PullRequestState::Open,
    ];

    $this->artisan('lanomat:recipes:pr-status')->assertSuccessful();

    expect($merged->fresh()?->pr_state)->toBe(PullRequestState::Merged)
        ->and($merged->fresh()?->pr_synced_at?->isToday())->toBeTrue()
        ->and($closed->fresh()?->pr_state)->toBe(PullRequestState::Closed)
        ->and($stillOpen->fresh()?->pr_state)->toBe(PullRequestState::Open);
});

it('continues when one pull request cannot be read', function () {
    commandTrigger();
    $fake = fakeRecipeRepository();

    $good = recipeWithPullRequest('good-game', 1);
    $broken = recipeWithPullRequest('broken-game', 2);

    $fake->states = [1 => PullRequestState::Merged];

    $exit = Artisan::call('lanomat:recipes:pr-status');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($good->fresh()?->pr_state)->toBe(PullRequestState::Merged)
        ->and($broken->fresh()?->pr_state)->toBe(PullRequestState::Open)
        ->and($output)->toContain('broken-game');
});

it('answers --json with what it checked and what failed', function () {
    commandTrigger();
    $fake = fakeRecipeRepository();

    recipeWithPullRequest('good-game', 1);
    recipeWithPullRequest('broken-game', 2);
    $fake->states = [1 => PullRequestState::Merged];

    $exit = Artisan::call('lanomat:recipes:pr-status', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($decoded)->toBeArray()
        ->and($decoded['checked'])->toBe(2)
        ->and($decoded['updated'][0]['state'])->toBe('merged')
        ->and($decoded['failed'][0]['slug'])->toBe('broken-game');
});

it('is a quiet no-op while pull requests are not configured', function () {
    commandTrigger();
    $fake = fakeRecipeRepository();
    $fake->configured = false;

    recipeWithPullRequest('open-game', 1);

    $exit = Artisan::call('lanomat:recipes:pr-status');

    expect($exit)->toBe(0)
        ->and(trim(Artisan::output()))->toBe('');
});

it('proposes one recipe from the command and prints state and url', function () {
    commandTrigger();
    fakeRecipeRepository();
    $user = User::factory()->create();

    $recipe = GameRecipe::factory()->contributedBy($user)->create([
        'slug' => 'tiny-racer',
        'title' => 'Tiny Racer',
    ]);

    $exit = Artisan::call('lanomat:recipes:propose', ['slug' => 'tiny-racer']);
    $output = preg_replace('/\s+/', ' ', Artisan::output()) ?? '';

    expect($exit)->toBe(0)
        ->and($output)->toContain('#1')
        ->and($output)->toContain('https://github.test/lanomat/pull/1')
        ->and($recipe->fresh()?->pr_state)->toBe(PullRequestState::Open);
});

it('exits non-zero for a blocked, failed, unconfigured or missing recipe', function () {
    commandTrigger();
    $fake = fakeRecipeRepository();
    $user = User::factory()->create();

    $blocked = GameRecipe::factory()->contributedBy($user)->create([
        'slug' => 'blocked-game',
        'steps' => [[
            'id' => 'join',
            'title' => 'Join',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Join the server.',
            'commands' => [['shell' => 'bash', 'run' => 'ping 192.168.10.10']],
        ]],
    ]);

    $exit = Artisan::call('lanomat:recipes:propose', ['slug' => 'blocked-game']);
    $output = preg_replace('/\s+/', ' ', Artisan::output()) ?? '';

    expect($exit)->toBe(1)
        ->and($output)->toContain('Nicht gesendet')
        ->and($output)->toContain('private IPv4 address')
        ->and($blocked->fresh()?->pr_state)->toBe(PullRequestState::Blocked);

    GameRecipe::factory()->contributedBy($user)->create(['slug' => 'failed-game']);
    $fake->failWith(RecipeRepositoryException::rejected(422, 'The branch is invalid.'));

    $exit = Artisan::call('lanomat:recipes:propose', ['slug' => 'failed-game']);
    $output = preg_replace('/\s+/', ' ', Artisan::output()) ?? '';

    expect($exit)->toBe(1)
        ->and($output)->toContain('Fehlgeschlagen');

    $fake->failWith(null);
    $fake->configured = false;

    $exit = Artisan::call('lanomat:recipes:propose', ['slug' => 'failed-game']);

    expect($exit)->toBe(1)
        ->and(preg_replace('/\s+/', ' ', Artisan::output()) ?? '')->toContain('nicht aktiviert');

    $exit = Artisan::call('lanomat:recipes:propose', ['slug' => 'missing-game']);

    expect($exit)->toBe(1)
        ->and(preg_replace('/\s+/', ' ', Artisan::output()) ?? '')->toContain('missing-game');
});

it('runs the status sync hourly and the file hashing nightly', function () {
    $events = collect(app(Schedule::class)->events());

    $status = $events->first(fn ($event): bool => str_contains((string) $event->command, 'lanomat:recipes:pr-status'));
    $hash = $events->first(fn ($event): bool => str_contains((string) $event->command, 'lanomat:files:hash'));

    expect($status)->not->toBeNull()
        ->and($status->expression)->toBe('0 * * * *')
        ->and($status->withoutOverlapping)->toBeTrue();

    expect($hash)->not->toBeNull()
        ->and($hash->expression)->toBe('45 4 * * *')
        ->and($hash->withoutOverlapping)->toBeTrue();
});
