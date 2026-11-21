<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\GameRecipes\Actions\ProposeRecipe;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Events\RecipeContributed;
use App\Modules\GameRecipes\Events\RecipePublished;
use App\Modules\GameRecipes\Exceptions\RecipeRepositoryException;
use App\Modules\GameRecipes\Jobs\ProposeRecipePullRequestJob;
use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

function sharedRecipe(User $user, array $overrides = []): GameRecipe
{
    return GameRecipe::factory()->contributedBy($user)->create([
        'slug' => 'tiny-racer',
        'title' => 'Tiny Racer',
        'summary' => 'A small racing game shared at the LAN.',
        'platforms' => ['windows'],
        'requirements' => [[
            'role' => 'base',
            'label' => 'Tiny Racer (portable archive)',
            'match' => ['tiny-racer.zip'],
            'required' => true,
        ]],
        'steps' => [[
            'id' => 'check-install',
            'title' => 'Unpack and check',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Unpack the archive into the target folder.',
            'verify' => [['type' => 'file_exists', 'path' => '{{target}}/TinyRacer.exe']],
        ]],
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
        'steps' => [[
            'id' => 'join',
            'title' => 'Join the server',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Start the game and join the LAN server.',
            'commands' => [['shell' => 'bash', 'run' => 'ping 10.20.30.40']],
        ]],
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
        ->toContain('Tiny Racer (portable archive)')
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
    $recipe = GameRecipe::factory()->create(['source' => 'bundled']);

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
