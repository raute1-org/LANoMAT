<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Actions\ContributeRecipe;
use App\Modules\GameRecipes\Contracts\RecipeRepository;
use App\Modules\GameRecipes\Domain\RecipeProposal;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Events\RecipeContributed;
use App\Modules\GameRecipes\Exceptions\RecipeRepositoryException;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\GitHubRecipeRepository;
use App\Modules\GameRecipes\Support\LauncherLineup;
use App\Modules\GameRecipes\Support\LauncherSupport;
use App\Modules\GameRecipes\Support\NullRecipeRepository;
use App\Modules\GameRecipes\Support\RecipeInstallLinks;
use App\Modules\GameServers\Contracts\GameInstallLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * The seams of game sharing: which recipes the launcher can install, the
 * lineup flag, the contribution action and the recipe repository binding.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sharedGamePayload(array $overrides = []): array
{
    return [
        'slug' => 'tiny-racer',
        'title' => 'Tiny Racer',
        'summary' => 'A small racing game shared at the LAN.',
        'platforms' => ['windows'],
        'requires' => [[
            'role' => 'base',
            'label' => 'Tiny Racer (portable archive)',
            'match' => ['tiny-racer.zip'],
        ]],
        'steps' => [[
            'id' => 'check-install',
            'title' => 'Unpack and check',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Unpack the archive.',
            'verify' => [['type' => 'file_exists', 'path' => '{{target}}/TinyRacer.exe']],
        ]],
        ...$overrides,
    ];
}

describe('launcher support', function () {
    it('supports the pilot, the hand-verified recipe and a browser recipe', function (string $slug, array $metadata) {
        $recipe = GameRecipe::factory()->make(['slug' => $slug, 'metadata' => $metadata]);

        expect(app(LauncherSupport::class)->supports($recipe))->toBeTrue();
    })->with([
        'pilot' => ['unreal-tournament-2004', []],
        'verified' => ['need-for-speed-underground-2', []],
        'browser' => ['some-browser-game', ['play_url' => 'https://play.example/']],
    ]);

    it('supports a portable recipe and nothing that only looks like one', function () {
        $support = app(LauncherSupport::class);
        $portable = GameRecipe::factory()->portable()->make();

        expect($support->supports($portable))->toBeTrue()
            ->and($support->isPortable($portable))->toBeTrue();

        $unmarked = GameRecipe::factory()->portable()->make();
        $unmarked->metadata = [...$unmarked->metadata, 'portable' => false];

        $noCheck = GameRecipe::factory()->portable()->make(['steps' => [[
            'id' => 'run', 'title' => 'Run setup', 'os' => 'any', 'optional' => false, 'detail' => 'Run it.',
        ]]]);

        $noBase = GameRecipe::factory()->portable()->make(['requirements' => [[
            'role' => 'mod', 'label' => 'A mod', 'match' => ['mod.zip'],
        ]]]);

        $noLaunch = GameRecipe::factory()->portable()->make();
        $noLaunch->metadata = ['portable' => true];

        expect($support->supports($unmarked))->toBeFalse()
            ->and($support->supports($noCheck))->toBeFalse()
            ->and($support->supports($noBase))->toBeFalse()
            ->and($support->supports($noLaunch))->toBeFalse()
            ->and($support->supports(GameRecipe::factory()->make()))->toBeFalse();
    });

    it('lets the orga override the decision in both directions', function () {
        $support = app(LauncherSupport::class);

        $off = GameRecipe::factory()->portable()->make();
        $off->metadata = [...$off->metadata, 'launcher_supported' => false];

        $on = GameRecipe::factory()->make(['metadata' => ['launcher_supported' => true]]);

        expect($support->supports($off))->toBeFalse()
            ->and($support->supports($on))->toBeTrue();
    });
});

describe('lineup flag', function () {
    it('adds a flagged recipe to a curated lineup', function () {
        config(['launcher.lineup' => 'unreal-tournament-2004']);
        GameRecipe::factory()->published()->inLineup()->create(['slug' => 'tiny-racer']);
        GameRecipe::factory()->published()->create(['slug' => 'hidden-game']);

        $lineup = app(LauncherLineup::class);

        expect($lineup->slugs())->toBe(['unreal-tournament-2004', 'tiny-racer'])
            ->and($lineup->allows('tiny-racer'))->toBeTrue()
            ->and($lineup->allows('hidden-game'))->toBeFalse()
            ->and($lineup->configuredSlugs())->toBe(['unreal-tournament-2004']);
    });

    it('stays unrestricted when no lineup is configured', function () {
        config(['launcher.lineup' => '']);
        GameRecipe::factory()->published()->inLineup()->create(['slug' => 'tiny-racer']);

        $lineup = app(LauncherLineup::class);

        expect($lineup->isUnrestricted())->toBeTrue()
            ->and($lineup->allows('anything-else'))->toBeTrue();
    });
});

describe('contributing', function () {
    it('creates a draft, requires the base file and announces the contribution', function () {
        Event::fake([RecipeContributed::class]);
        $user = User::factory()->create();

        $recipe = app(ContributeRecipe::class)->handle($user, sharedGamePayload(['via' => 'web']));

        expect($recipe->status)->toBe(RecipeStatus::Draft)
            ->and($recipe->source)->toBe(GameRecipe::SOURCE_CONTRIBUTION)
            ->and($recipe->source_ref)->toBe('web')
            ->and($recipe->contributor_user_id)->toBe($user->id)
            ->and($recipe->isContribution())->toBeTrue()
            ->and($recipe->in_lineup)->toBeFalse()
            ->and($recipe->metadata)->toBe(['contributed_via' => 'web'])
            ->and($recipe->files()->first()->required)->toBeTrue();

        Event::assertDispatched(
            RecipeContributed::class,
            fn (RecipeContributed $event): bool => $event->recipe->is($recipe) && $event->userId === $user->id,
        );
    });

    it('pins the contributor\'s own upload and keeps the pin out of the recipe text', function () {
        $user = User::factory()->create();
        $file = SharedFile::factory()->create(['user_id' => $user->id, 'original_name' => 'tiny-racer.zip']);

        $payload = sharedGamePayload();
        $payload['requires'][0]['shared_file_id'] = $file->id;

        $recipe = app(ContributeRecipe::class)->handle($user, $payload);

        expect($recipe->files()->first()->shared_file_id)->toBe($file->id)
            ->and($recipe->requirements[0])->not->toHaveKey('shared_file_id');
    });

    it('refuses to pin a file the contributor may not see', function () {
        $user = User::factory()->create();
        $foreign = SharedFile::factory()->create(['original_name' => 'secret.zip']);

        $payload = sharedGamePayload();
        $payload['requires'][0]['shared_file_id'] = $foreign->id;

        expect(fn () => app(ContributeRecipe::class)->handle($user, $payload))
            ->toThrow(ValidationException::class);

        expect(GameRecipe::query()->where('slug', 'tiny-racer')->exists())->toBeFalse();
    });

    it('keeps launch targets and an existing pin when the contributor edits the draft', function () {
        $user = User::factory()->create();
        $file = SharedFile::factory()->approved()->create(['original_name' => 'tiny-racer.zip']);
        $launch = [['id' => 'windows', 'label' => 'Windows', 'os' => 'windows', 'command' => '{{target}}/TinyRacer.exe']];

        $recipe = app(ContributeRecipe::class)->handle($user, sharedGamePayload([
            'launch_targets' => $launch,
            'metadata' => ['portable' => true],
        ]));
        $recipe->files()->first()->update(['shared_file_id' => $file->id]);

        $edited = app(ContributeRecipe::class)->handle($user, sharedGamePayload(['summary' => 'Now with a better summary.']));

        expect($edited->summary)->toBe('Now with a better summary.')
            // jsonb does not keep key order, so compare by value.
            ->and($edited->metadata['launch_targets'])->toEqual($launch)
            ->and($edited->metadata['portable'])->toBeTrue()
            ->and($edited->files()->first()->shared_file_id)->toBe($file->id)
            ->and(app(LauncherSupport::class)->supports($edited))->toBeTrue();
    });

    it('ignores the launcher override from a participant and refuses a non-http link', function () {
        $user = User::factory()->create();

        $recipe = app(ContributeRecipe::class)->handle($user, sharedGamePayload([
            'metadata' => ['launcher_supported' => true],
        ]));

        expect($recipe->metadata)->not->toHaveKey('launcher_supported');

        expect(fn () => app(ContributeRecipe::class)->handle($user, sharedGamePayload([
            'slug' => 'other-game',
            'play_url' => 'javascript:alert(1)',
        ])))->toThrow(ValidationException::class);
    });
});

describe('recipe repository', function () {
    it('is the null repository until the feature, the repository and the token are set', function (array $config) {
        config(['recipes.pull_requests' => [...config('recipes.pull_requests'), ...$config]]);

        $repository = app(RecipeRepository::class);

        expect($repository)->toBeInstanceOf(NullRecipeRepository::class)
            ->and($repository->isConfigured())->toBeFalse();

        expect(fn () => $repository->state(1))->toThrow(RecipeRepositoryException::class);
    })->with([
        'default' => [[]],
        'enabled without token' => [['enabled' => true, 'repository' => 'lanomat/recipes']],
        'token without enabled' => [['enabled' => false, 'repository' => 'lanomat/recipes', 'token' => 'test-token']],
        'enabled without repository' => [['enabled' => true, 'token' => 'test-token']],
    ]);

    it('is the GitHub repository when configured, and a fake in tests', function () {
        config(['recipes.pull_requests' => [
            ...config('recipes.pull_requests'),
            'enabled' => true,
            'repository' => 'lanomat/recipes',
            'token' => 'test-token',
        ]]);

        expect(app(RecipeRepository::class))->toBeInstanceOf(GitHubRecipeRepository::class)
            ->and(app(RecipeRepository::class)->isConfigured())->toBeTrue();

        $fake = fakeRecipeRepository();
        $proposal = new RecipeProposal('tiny-racer', 'recipes/tiny-racer', 'feat(recipes): add Tiny Racer', 'Tiny Racer', 'Body', [
            'resources/recipes/tiny-racer/recipe.yaml' => "slug: tiny-racer\n",
        ]);

        $first = app(RecipeRepository::class)->propose($proposal);
        $again = app(RecipeRepository::class)->propose($proposal);

        expect($first->number)->toBe($again->number)
            ->and($first->state)->toBe(PullRequestState::Open)
            ->and($fake->proposals)->toHaveCount(2);

        $fake->assertProposed('tiny-racer');
    });

    it('describes a pull request for a page only once one was proposed', function () {
        $recipe = GameRecipe::factory()->create();

        expect($recipe->pullRequest())->toBeNull();

        $recipe->forceFill([
            'pr_number' => 7,
            'pr_url' => 'https://github.test/lanomat/pull/7',
            'pr_state' => PullRequestState::Open,
        ])->save();

        expect($recipe->refresh()->pullRequest())->toBe([
            'number' => 7,
            'url' => 'https://github.test/lanomat/pull/7',
            'state' => 'open',
            'label' => 'Pull Request offen',
        ]);

        $recipe->forceFill(['pr_state' => PullRequestState::Blocked])->save();

        expect($recipe->refresh()->pullRequest()['url'])->toBeNull();
    });
});

it('answers install links through the recipe catalogue while GameRecipes is on', function () {
    expect(app(GameInstallLinks::class))->toBeInstanceOf(RecipeInstallLinks::class);
});
