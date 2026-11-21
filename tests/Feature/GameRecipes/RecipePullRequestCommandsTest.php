<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\GameRecipes\Console\SyncRecipePullRequestsCommand;
use App\Modules\GameRecipes\Contracts\RecipeRepository;
use App\Modules\GameRecipes\Domain\PullRequestRef;
use App\Modules\GameRecipes\Domain\RecipeProposal;
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

/** A contributed recipe the catalogue validator would accept. */
function commandReadyRecipe(User $user, string $slug = 'tiny-racer', array $overrides = []): GameRecipe
{
    $title = ucwords(str_replace('-', ' ', $slug));
    $skill = [
        '---',
        'name: '.$slug,
        'description: "Install '.$title.' from the LAN share when a player asks for it."',
        '---',
        '',
        '# '.$title,
        '',
        'This draft needs verification on the LAN before the orga publishes it.',
    ];

    while (count($skill) < 70) {
        $skill[] = '- Unpack the archive and check the executable.';
    }

    $steps = fn (string $command): array => [
        [
            'id' => 'get-archive',
            'title' => 'Get the archive',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Download the archive from the LAN file host.',
        ],
        [
            'id' => 'unpack',
            'title' => 'Unpack',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Unpack the archive into {{target}}.',
            'commands' => [['shell' => 'bash', 'run' => $command]],
        ],
        [
            'id' => 'check',
            'title' => 'Check',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Confirm the executable exists. This draft needs verification on the LAN.',
        ],
    ];

    return GameRecipe::factory()->contributedBy($user)->create([
        'slug' => $slug,
        'title' => $title,
        'summary' => 'A small game shared at the LAN.',
        'platforms' => ['windows'],
        'skill_markdown' => implode("\n", $skill)."\n",
        'steps' => $steps('bsdtar -xf "{{downloads}}/'.$slug.'.zip" -C "{{target}}"'),
        ...$overrides,
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

    $recipe = commandReadyRecipe($user);

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

    $blocked = commandReadyRecipe($user, 'blocked-game', [
        'steps' => [[
            'id' => 'join',
            'title' => 'Join',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Join the server. This draft needs verification on the LAN.',
            'commands' => [['shell' => 'bash', 'run' => 'ping 192.168.10.10']],
        ]],
    ]);

    $exit = Artisan::call('lanomat:recipes:propose', ['slug' => 'blocked-game']);
    $output = preg_replace('/\s+/', ' ', Artisan::output()) ?? '';

    expect($exit)->toBe(1)
        ->and($output)->toContain('Nicht gesendet')
        ->and($output)->toContain('private IPv4 address')
        ->and($blocked->fresh()?->pr_state)->toBe(PullRequestState::Blocked);

    commandReadyRecipe($user, 'failed-game');
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

it('writes no state when the pull request number changed while it was asked', function () {
    commandTrigger();

    $recipe = recipeWithPullRequest('replaced-game', 1);
    $syncedAt = $recipe->pr_synced_at;

    $repository = new class($recipe) implements RecipeRepository
    {
        public function __construct(private readonly GameRecipe $recipe) {}

        public function isConfigured(): bool
        {
            return true;
        }

        public function propose(RecipeProposal $proposal): PullRequestRef
        {
            throw RecipeRepositoryException::rejected(422, 'not used in this test');
        }

        public function state(int $number): PullRequestState
        {
            // A newer proposal replaced the pull request while #1 was asked.
            $this->recipe->forceFill(['pr_number' => $number + 98])->save();

            return PullRequestState::Merged;
        }

        public function read(string $path): ?string
        {
            return null;
        }
    };

    app()->instance(RecipeRepository::class, $repository);

    $this->artisan('lanomat:recipes:pr-status')->assertSuccessful();

    $fresh = $recipe->fresh();

    expect($fresh?->pr_state)->toBe(PullRequestState::Open)
        ->and($fresh?->pr_number)->toBe(99)
        ->and($fresh?->pr_synced_at?->equalTo($syncedAt))->toBeTrue();
});

it('says in its description which pull requests it asks about', function () {
    $description = (new SyncRecipePullRequestsCommand)->getDescription();

    expect($description)->toContain('believes are open');
});
