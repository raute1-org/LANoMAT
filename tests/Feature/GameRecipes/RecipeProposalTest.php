<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Actions\ContributeRecipe;
use App\Modules\GameRecipes\Actions\ProposeRecipe;
use App\Modules\GameRecipes\Actions\ShareGame;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Events\RecipeContributed;
use App\Modules\GameRecipes\Events\RecipePublished;
use App\Modules\GameRecipes\Exceptions\RecipeRepositoryException;
use App\Modules\GameRecipes\Jobs\ProposeRecipePullRequestJob;
use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * The path from "a participant shares" to "a pull request exists": the
 * listener decides whether, the job runs it, the action exports, checks,
 * proposes and stores the result. No GitHub: the fake repository stands in.
 */
function pipelineTrigger(string $trigger = 'contribute', bool $attribution = false): void
{
    config([
        'recipes.pull_requests.enabled' => true,
        'recipes.pull_requests.trigger' => $trigger,
        'recipes.pull_requests.attribution' => $attribution,
        'recipes.pull_requests.base' => 'main',
        'recipes.pull_requests.branch_prefix' => 'recipes/',
        'recipes.pull_requests.path' => 'resources/recipes',
    ]);
}

/** A SKILL.md the catalogue validator accepts: front matter and enough lines. */
function proposalReadySkill(string $slug = 'tiny-racer', string $title = 'Tiny Racer'): string
{
    $skill = [
        '---',
        'name: '.$slug,
        'description: "Install '.$title.' from the LAN share when a player asks for it."',
        '---',
        '',
        '# '.$title,
        '',
        'Use this skill when a player wants '.$title.' installed on the LAN.',
        'This draft needs verification on the LAN before the orga publishes it.',
    ];

    while (count($skill) < 70) {
        $skill[] = '- Unpack the archive into the target folder and check the executable.';
    }

    return implode("\n", $skill)."\n";
}

/**
 * Three steps, as the catalogue requires of a draft, with the verification
 * prose the validator looks for.
 *
 * @return list<array<string, mixed>>
 */
function proposalReadySteps(): array
{
    return [
        [
            'id' => 'get-archive',
            'title' => 'Get the portable archive',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Download the archive from the LAN file host.',
        ],
        [
            'id' => 'unpack',
            'title' => 'Unpack the archive',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Unpack the archive into {{target}}.',
            'commands' => [['shell' => 'bash', 'run' => 'bsdtar -xf "{{downloads}}/tiny-racer.zip" -C "{{target}}"']],
        ],
        [
            'id' => 'check-install',
            'title' => 'Check the install',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Confirm the executable exists. This draft needs verification on the LAN.',
            'verify' => [['type' => 'file_exists', 'path' => '{{target}}/TinyRacer.exe']],
        ],
    ];
}

function sharedRecipe(User $user, array $overrides = []): GameRecipe
{
    return GameRecipe::factory()->contributedBy($user)->create([
        'slug' => 'tiny-racer',
        'title' => 'Tiny Racer',
        'summary' => 'A small racing game shared at the LAN.',
        'platforms' => ['windows'],
        'skill_markdown' => proposalReadySkill(),
        'requirements' => [[
            'role' => 'base',
            'label' => 'Tiny Racer (portable archive)',
            'match' => ['tiny-racer.zip'],
            'required' => true,
        ]],
        'steps' => proposalReadySteps(),
        ...$overrides,
    ]);
}

function proposalCatalog(): string
{
    return <<<'MARKDOWN'
# Recipe catalog

## Published

| Slug | Title | Platforms | Status | One line |
| ---- | ----- | --------- | ------ | -------- |
| `alpha-game` | Alpha | windows | published | First one. |

## Draft

| Slug | Title | Platforms | Status | One line |
| ---- | ----- | --------- | ------ | -------- |
| `draft-game` | Draft | windows | draft | Waits for the LAN. |

## Archived

| Slug | Title | Platforms | Status | One line |
| ---- | ----- | --------- | ------ | -------- |
| `old-game` | Old | windows | archived | Gone. |
MARKDOWN;
}

it('waits for the orga by default, because the trigger ships as publish', function () {
    expect(config('recipes.pull_requests.trigger'))->toBe('publish');

    fakeRecipeRepository();
    config([
        'recipes.pull_requests.enabled' => true,
        'recipes.pull_requests.repository' => 'lanomat/recipes',
        'recipes.pull_requests.token' => 'test-token',
    ]);

    $user = User::factory()->create();
    $recipe = sharedRecipe($user);

    RecipeContributed::dispatch($recipe, $user->id);

    expect($recipe->fresh()->pr_state)->toBeNull();
});

it('proposes once when a participant shares and the trigger is contribute', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $user = User::factory()->create();

    $recipe = sharedRecipe($user);

    RecipeContributed::dispatch($recipe, $user->id);

    $fake->assertProposed('tiny-racer');

    $proposal = $fake->lastProposal();
    $fresh = $recipe->fresh();

    expect($fake->proposals)->toHaveCount(1)
        ->and($proposal)->not->toBeNull()
        ->and($proposal->branch)->toBe('recipes/tiny-racer')
        ->and($proposal->title)->toBe('feat(recipes): add Tiny Racer')
        ->and(array_keys($proposal->files))->toBe([
            'resources/recipes/tiny-racer/recipe.yaml',
            'resources/recipes/tiny-racer/SKILL.md',
        ])
        ->and($fresh)->toBeInstanceOf(GameRecipe::class)
        ->and($fresh->pr_number)->toBe(1)
        ->and($fresh->pr_url)->toBe('https://github.test/lanomat/pull/1')
        ->and($fresh->pr_branch)->toBe('recipes/tiny-racer')
        ->and($fresh->pr_state)->toBe(PullRequestState::Open)
        ->and($fresh->pr_error)->toBeNull()
        ->and($fresh->pr_synced_at)->not->toBeNull();
});

it('waits for the publish trigger on a contribution and opens it on RecipePublished', function () {
    pipelineTrigger('publish');
    $fake = fakeRecipeRepository();
    $user = User::factory()->create();
    $orga = User::factory()->orga()->create();

    $recipe = sharedRecipe($user);

    RecipeContributed::dispatch($recipe, $user->id);

    $fake->assertNothingProposed();
    expect($recipe->fresh()->pr_state)->toBeNull();

    RecipePublished::dispatch($recipe, $orga->id);

    $fake->assertProposed('tiny-racer');
    expect($recipe->fresh()->pr_state)->toBe(PullRequestState::Open);
});

it('does nothing and writes no state while pull requests are not configured', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $fake->configured = false;
    $user = User::factory()->create();

    $recipe = sharedRecipe($user);

    RecipeContributed::dispatch($recipe, $user->id);

    $fake->assertNothingProposed();

    $fresh = $recipe->fresh();

    expect($fresh->pr_state)->toBeNull()
        ->and($fresh->pr_number)->toBeNull()
        ->and($fresh->pr_synced_at)->toBeNull();
});

it('blocks a recipe with a private address in a step command and sends nothing', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $user = User::factory()->create();

    $recipe = sharedRecipe($user, [
        'steps' => [
            ...array_slice(proposalReadySteps(), 0, 2),
            [
                'id' => 'join',
                'title' => 'Join the server',
                'os' => 'any',
                'optional' => false,
                'detail' => 'Start the game and join the LAN server. This draft needs verification on the LAN.',
                'commands' => [['shell' => 'bash', 'run' => 'ping 10.20.30.40']],
            ],
        ],
    ]);

    app(ProposeRecipe::class)->handle($recipe);

    $fake->assertNothingProposed();

    $fresh = $recipe->fresh();

    expect($fresh->pr_state)->toBe(PullRequestState::Blocked)
        ->and($fresh->pr_number)->toBeNull()
        ->and($fresh->pr_synced_at)->not->toBeNull()
        ->and($fresh->pr_error)->toContain('private IPv4 address')
        ->and($fresh->pr_error)->not->toContain('10.20.30.40')
        ->and($fresh->pr_error)->toContain('10[REDACTED len=11]');
});

it('describes the recipe in the pull request body without instance data', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $user = User::factory()->create(['name' => 'Alyx Vance']);

    $recipe = sharedRecipe($user);

    app(ProposeRecipe::class)->handle($recipe);

    $proposal = $fake->lastProposal();
    expect($proposal)->not->toBeNull();

    $body = $proposal->body;

    expect($body)->toContain('`tiny-racer`')
        ->toContain('windows')
        ->toContain('draft')
        ->toContain('Tiny Racer \(portable archive\)')
        ->toContain('validate.mjs')
        ->toContain('Licence checked')
        ->toContain('Opened automatically by LANoMAT')
        ->toContain('Game files are not part of this pull request')
        // Attribution is off by default: the contributor's name stays local.
        ->not->toContain('Alyx Vance')
        ->not->toContain('shared_file_id');

    foreach ($proposal->files as $content) {
        expect($content)->not->toContain('shared_file_id')
            ->not->toContain('lan.example/dl/');
    }
});

it('names the contributor only when attribution is on', function () {
    pipelineTrigger('contribute', attribution: true);
    $fake = fakeRecipeRepository();
    $user = User::factory()->create(['name' => 'Alyx Vance']);

    app(ProposeRecipe::class)->handle(sharedRecipe($user));

    $proposal = $fake->lastProposal();
    expect($proposal)->not->toBeNull()
        ->and($proposal->body)->toContain('Shared by Alyx Vance at the LAN.');
});

it('adds and updates the catalogue row in the table of the recipe status', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $fake->files = ['resources/recipes/_catalog.md' => proposalCatalog()];
    $user = User::factory()->create();

    app(ProposeRecipe::class)->handle(sharedRecipe($user));

    $proposal = $fake->lastProposal();
    expect($proposal)->not->toBeNull();

    $catalog = $proposal->files['resources/recipes/_catalog.md'] ?? '';

    [$draft] = explode('## Archived', $catalog, 2);

    expect($catalog)->toContain('| `tiny-racer` |')
        ->and($draft)->toContain('`tiny-racer`')
        ->and(substr_count($catalog, '`tiny-racer`'))->toBe(1);
});

it('says update when the recipe already exists on the base branch', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $fake->files = ['resources/recipes/tiny-racer/recipe.yaml' => "slug: tiny-racer\n"];
    $user = User::factory()->create();

    app(ProposeRecipe::class)->handle(sharedRecipe($user));

    $proposal = $fake->lastProposal();
    expect($proposal)->not->toBeNull()
        ->and($proposal->title)->toBe('feat(recipes): update Tiny Racer')
        ->and($proposal->commitMessage)->toBe('feat(recipes): update Tiny Racer');
});

it('does not propose again when the base branch already carries every file', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $user = User::factory()->create();

    $recipe = sharedRecipe($user);

    app(ProposeRecipe::class)->handle($recipe);
    $first = $fake->lastProposal();
    expect($first)->not->toBeNull();

    // The pull request was merged: the base branch now has exactly these files.
    $fake->files = $first->files;

    app(ProposeRecipe::class)->handle($recipe->fresh());

    expect($fake->proposals)->toHaveCount(1);
});

it('rethows a transient failure and leaves the recipe failed without a pull request', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $user = User::factory()->create();
    $recipe = sharedRecipe($user);

    $fake->failWith(RecipeRepositoryException::unreachable('the connection failed'));

    expect(fn () => app(ProposeRecipe::class)->handle($recipe))
        ->toThrow(RecipeRepositoryException::class);

    $fresh = $recipe->fresh();

    expect($fresh->pr_state)->toBe(PullRequestState::Failed)
        ->and($fresh->pr_error)->toContain('not reachable')
        ->and($fresh->pr_synced_at)->not->toBeNull();
});

it('keeps an open pull request when a transient failure happens later', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $user = User::factory()->create();
    $recipe = sharedRecipe($user);

    $recipe->forceFill([
        'pr_number' => 7,
        'pr_url' => 'https://github.test/lanomat/pull/7',
        'pr_branch' => 'recipes/tiny-racer',
        'pr_state' => PullRequestState::Open,
    ])->save();

    $fake->failWith(RecipeRepositoryException::unreachable('rate limit'));

    expect(fn () => app(ProposeRecipe::class)->handle($recipe))
        ->toThrow(RecipeRepositoryException::class);

    $fresh = $recipe->fresh();

    expect($fresh->pr_state)->toBe(PullRequestState::Open)
        ->and($fresh->pr_number)->toBe(7)
        ->and($fresh->pr_error)->toContain('rate limit');
});

it('swallows a permanent failure and records why', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $user = User::factory()->create();
    $recipe = sharedRecipe($user);

    $fake->failWith(RecipeRepositoryException::rejected(422, 'The branch name is invalid.'));

    $result = app(ProposeRecipe::class)->handle($recipe);

    $fresh = $result->fresh();

    expect($fresh->pr_state)->toBe(PullRequestState::Failed)
        ->and($fresh->pr_error)->toContain('refused the request (422)');
});

it('leaves a synced recipe alone unless it already has a pull request', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $recipe = GameRecipe::factory()->create([
        'slug' => 'synced-game',
        'title' => 'Synced Game',
        'source' => 'bundled',
        'skill_markdown' => proposalReadySkill('synced-game', 'Synced Game'),
        'steps' => proposalReadySteps(),
        'summary' => 'A synced game that is already in the catalogue.',
        'platforms' => ['windows'],
    ]);

    app(ProposeRecipe::class)->handle($recipe);

    $fake->assertNothingProposed();
    expect($recipe->fresh()->pr_state)->toBeNull();

    $recipe->forceFill([
        'pr_number' => 3,
        'pr_state' => PullRequestState::Open,
    ])->save();

    app(ProposeRecipe::class)->handle($recipe->fresh());

    $fake->assertProposed($recipe->slug);
});

it('records a missing catalogue section as a blocked recipe instead of proposing', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $fake->files = ['resources/recipes/_catalog.md' => "# Catalog\n\nNo tables here.\n"];
    $user = User::factory()->create();

    app(ProposeRecipe::class)->handle(sharedRecipe($user));

    $fake->assertNothingProposed();

    expect(GameRecipe::query()->where('slug', 'tiny-racer')->first()?->pr_state)->toBe(PullRequestState::Blocked);
});

it('records a permanent proposal failure in the job when it finally fails', function () {
    pipelineTrigger('contribute');
    fakeRecipeRepository();
    $user = User::factory()->create();
    $recipe = sharedRecipe($user);

    $job = new ProposeRecipePullRequestJob((int) $recipe->getKey());

    $job->failed(new RecipeRepositoryException('The repository refused the request (422): nope.'));

    expect($recipe->fresh()->pr_state)->toBe(PullRequestState::Failed)
        ->and($recipe->fresh()->pr_error)->toContain('nope');

    $withPullRequest = sharedRecipe($user, ['slug' => 'other-racer', 'title' => 'Other Racer']);
    $withPullRequest->forceFill(['pr_number' => 9, 'pr_state' => PullRequestState::Open])->save();

    (new ProposeRecipePullRequestJob((int) $withPullRequest->getKey()))
        ->failed(new RecipeRepositoryException('late failure'));

    expect($withPullRequest->fresh()->pr_state)->toBe(PullRequestState::Open)
        ->and($withPullRequest->fresh()->pr_error)->toBeNull();

    // A deleted recipe is a no-op, not an error.
    (new ProposeRecipePullRequestJob(999999))->failed(null);
});

it('proposes the share page output without a catalogue problem', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $user = User::factory()->create();
    $event = Event::factory()->registration()->create();
    $archive = SharedFile::factory()->approved()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'original_name' => 'tiny-racer.zip',
    ]);

    $recipe = app(ShareGame::class)->handle($user, [
        'title' => 'Tiny Racer',
        'shared_file_id' => $archive->id,
        'platforms' => ['windows', 'linux'],
        'executable' => 'bin/TinyRacer.exe',
        'linux_runner' => 'wine',
        'multiplayer' => true,
    ]);

    // The builder's SKILL.md and its steps satisfy the repository validator.
    $fake->assertProposed('tiny-racer');

    expect($recipe->refresh()->pr_state)->toBe(PullRequestState::Open)
        ->and(array_keys($fake->lastProposal()->files ?? []))->toBe([
            'resources/recipes/tiny-racer/recipe.yaml',
            'resources/recipes/tiny-racer/SKILL.md',
        ]);
});

it('blocks a bare MCP contribution and names every catalogue reason', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $user = User::factory()->create();

    $recipe = app(ContributeRecipe::class)->handle($user, [
        'slug' => 'bare-game',
        'title' => 'Bare Game',
        'summary' => 'A game added through the MCP tool.',
        'platforms' => ['windows'],
        'requires' => [[
            'role' => 'base',
            'label' => 'Bare game archive',
            'match' => ['bare-game.zip'],
            'required' => true,
        ]],
        'steps' => [[
            'id' => 'install',
            'title' => 'Install',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Unpack the archive.',
        ]],
        'via' => 'mcp',
    ]);

    app(ProposeRecipe::class)->handle($recipe);

    $fake->assertNothingProposed();

    $fresh = $recipe->fresh();

    expect($fresh->pr_state)->toBe(PullRequestState::Blocked)
        ->and($fresh->pr_error)->toContain('SKILL.md has')
        ->and($fresh->pr_error)->toContain('60 to 150')
        ->and($fresh->pr_error)->toContain('steps for a draft')
        ->and($fresh->pr_error)->toContain('needs verification on the LAN');
});

it('does not hold a published recipe to the draft rules', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $user = User::factory()->create();

    $recipe = sharedRecipe($user, [
        'status' => RecipeStatus::Published,
        'steps' => [proposalReadySteps()[0]],
        'description' => 'A published racing game.',
    ]);

    app(ProposeRecipe::class)->handle($recipe);

    $fake->assertProposed('tiny-racer');
    expect($recipe->fresh()->pr_state)->toBe(PullRequestState::Open);
});

it('keeps an open pull request when the text becomes unsafe', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $user = User::factory()->create();
    $recipe = sharedRecipe($user);

    app(ProposeRecipe::class)->handle($recipe);

    expect($recipe->refresh()->pr_number)->toBe(1);

    $recipe->forceFill(['steps' => [
        ...array_slice(proposalReadySteps(), 0, 2),
        [
            'id' => 'join',
            'title' => 'Join the server',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Join the LAN server. This draft needs verification on the LAN.',
            'commands' => [['shell' => 'bash', 'run' => 'ping 10.20.30.40']],
        ],
    ]])->save();

    $recipe = app(ProposeRecipe::class)->handle($recipe->refresh());

    expect($recipe->refresh()->pr_state)->toBe(PullRequestState::Open)
        ->and($recipe->pr_number)->toBe(1)
        ->and($recipe->pr_url)->toBe('https://github.test/lanomat/pull/1')
        ->and($recipe->pr_error)->toContain('private IPv4 address')
        ->and($fake->proposals)->toHaveCount(1);

    // The status sync still knows the pull request; it stayed open.
    $fake->states[1] = PullRequestState::Merged;

    $this->artisan('lanomat:recipes:pr-status')->assertSuccessful();

    expect($recipe->refresh()->pr_state)->toBe(PullRequestState::Merged);
});

it('queues the proposal only after the transaction commits', function () {
    pipelineTrigger('contribute');
    fakeRecipeRepository();
    Queue::fake();

    $user = User::factory()->create();
    $recipe = sharedRecipe($user);

    DB::transaction(function () use ($recipe, $user): void {
        RecipeContributed::dispatch($recipe, $user->id);

        Queue::assertNotPushed(ProposeRecipePullRequestJob::class);
    });

    Queue::assertPushed(ProposeRecipePullRequestJob::class, 1);
});

it('retries a missing recipe instead of returning silently', function () {
    $job = new ProposeRecipePullRequestJob(987654);

    $retry = new class
    {
        /** @var list<int> */
        public array $released = [];

        public function attempts(): int
        {
            return 1;
        }

        public function release(int $delay): void
        {
            $this->released[] = $delay;
        }
    };

    $job->job = $retry;
    $job->handle(app(ProposeRecipe::class));

    expect($retry->released)->toHaveCount(1)
        ->and($retry->released[0])->toBeGreaterThan(0);

    $last = new class
    {
        /** @var list<int> */
        public array $released = [];

        public function attempts(): int
        {
            return 3;
        }

        public function release(int $delay): void
        {
            $this->released[] = $delay;
        }
    };

    $lastJob = new ProposeRecipePullRequestJob(987654);
    $lastJob->job = $last;
    $lastJob->handle(app(ProposeRecipe::class));

    expect($last->released)->toBe([]);
});

it('stops a contributor after the hourly proposal budget', function () {
    pipelineTrigger('contribute');
    config(['recipes.pull_requests.per_user_hourly' => 1]);

    $fake = fakeRecipeRepository();
    $user = User::factory()->create();

    RecipeContributed::dispatch(sharedRecipe($user), $user->id);

    $fake->assertProposed('tiny-racer');

    $second = sharedRecipe($user, [
        'slug' => 'second-racer',
        'title' => 'Second Racer',
        'skill_markdown' => proposalReadySkill('second-racer', 'Second Racer'),
    ]);

    Log::spy();

    RecipeContributed::dispatch($second, $user->id);

    expect($fake->proposals)->toHaveCount(1)
        ->and($second->fresh()->pr_state)->toBeNull();

    Log::shouldHaveReceived('notice')
        ->with('recipes.pull_request_skipped', Mockery::on(
            fn (mixed $context): bool => is_array($context) && ($context['reason'] ?? null) === 'per_user_hourly',
        ))
        ->once();
});

it('stops at the open pull request ceiling but still updates an existing pull request', function () {
    pipelineTrigger('contribute');
    config(['recipes.pull_requests.max_open' => 1]);

    $fake = fakeRecipeRepository();
    $user = User::factory()->create();

    $open = sharedRecipe($user, [
        'slug' => 'open-game',
        'title' => 'Open Game',
        'skill_markdown' => proposalReadySkill('open-game', 'Open Game'),
    ]);
    $open->forceFill(['pr_number' => 99, 'pr_state' => PullRequestState::Open])->save();

    $new = sharedRecipe($user, [
        'slug' => 'new-game',
        'title' => 'New Game',
        'skill_markdown' => proposalReadySkill('new-game', 'New Game'),
    ]);

    Log::spy();

    RecipeContributed::dispatch($new, $user->id);

    expect($new->fresh()->pr_state)->toBeNull();
    $fake->assertNothingProposed();

    Log::shouldHaveReceived('notice')
        ->with('recipes.pull_request_skipped', Mockery::on(
            fn (mixed $context): bool => is_array($context) && ($context['reason'] ?? null) === 'max_open',
        ))
        ->once();

    $existing = sharedRecipe($user, [
        'slug' => 'existing-game',
        'title' => 'Existing Game',
        'skill_markdown' => proposalReadySkill('existing-game', 'Existing Game'),
    ]);
    $existing->forceFill([
        'pr_number' => 7,
        'pr_url' => 'https://github.test/lanomat/pull/7',
        'pr_branch' => 'recipes/existing-game',
        'pr_state' => PullRequestState::Open,
    ])->save();

    RecipeContributed::dispatch($existing, $user->id);

    expect($fake->proposals)->toHaveCount(1);
});

it('does not propose an archived recipe', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $user = User::factory()->create();

    $recipe = sharedRecipe($user, ['status' => RecipeStatus::Archived]);

    RecipeContributed::dispatch($recipe, $user->id);

    $fake->assertNothingProposed();
    expect($recipe->fresh()->pr_state)->toBeNull();

    $archivedWithPullRequest = sharedRecipe($user, [
        'slug' => 'archived-game',
        'title' => 'Archived Game',
        'skill_markdown' => proposalReadySkill('archived-game', 'Archived Game'),
        'status' => RecipeStatus::Archived,
    ]);
    $archivedWithPullRequest->forceFill([
        'pr_number' => 5,
        'pr_url' => 'https://github.test/lanomat/pull/5',
        'pr_state' => PullRequestState::Open,
    ])->save();

    app(ProposeRecipe::class)->handle($archivedWithPullRequest->fresh() ?? $archivedWithPullRequest);

    expect($fake->proposals)->toHaveCount(0)
        ->and($archivedWithPullRequest->fresh()?->pr_state)->toBe(PullRequestState::Open);
});

it('neutralises participant markdown in the title and body', function () {
    pipelineTrigger('contribute');
    $fake = fakeRecipeRepository();
    $user = User::factory()->create();

    $recipe = sharedRecipe($user, [
        'title' => 'Tiny ![x](https://track.example/x.gif) @org/team Fixes #12 <b>bold</b> `code` a|b',
        'summary' => 'Image ![y](https://track.example/y.gif) and a | table',
    ]);

    app(ProposeRecipe::class)->handle($recipe);

    $proposal = $fake->lastProposal();
    expect($proposal)->not->toBeNull();

    $title = $proposal->title;
    $body = $proposal->body;

    expect($title)->toContain('\!\[x\]')
        ->and($title)->not->toContain('](https')
        ->and($title)->toContain('\@org/team')
        ->and($title)->toContain('Fixes \#12')
        ->and($title)->toContain('\<b\>')
        ->and($title)->toContain('\`code\`')
        ->and($title)->toContain('a\|b')
        ->and($body)->toContain('\!\[y\]')
        ->and($body)->toContain('\|')
        ->and($body)->toContain('\@org/team');
});
