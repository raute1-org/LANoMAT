<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\LauncherSupport;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Support\McpCodes;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    // This file exercises visibility/capability flags across the catalogue;
    // the curated first lineup has its own tests.
    Config::set('launcher.lineup', '');
});

beforeAll(function (): void {
    recipeIndexReachableDatabase();
});

/**
 * The dev stack puts Postgres on 127.0.0.1:5434, but a developer machine can
 * already have that port taken by an unrelated project (the LANoMAT container
 * then runs on another host port). A connection error is not a recipe failure:
 * probe the usual ports once, with the credentials from `.env.testing`, and keep
 * the first one that answers. A DB_PORT exported in the real environment is
 * tried first and always wins.
 */
function recipeIndexReachableDatabase(): void
{
    $file = dirname(__DIR__, 3).'/.env.testing';
    $env = is_file($file) ? Dotenv::parse((string) file_get_contents($file)) : [];
    $get = static fn (string $key, string $default): string => (string) ($_ENV[$key]
        ?? $_SERVER[$key]
        ?? $env[$key]
        ?? $default);

    if ($get('DB_CONNECTION', 'pgsql') !== 'pgsql') {
        return; // another test database is the caller's own setup
    }

    $host = $get('DB_HOST', '127.0.0.1');

    if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
        return; // a dedicated test host needs no guessing
    }

    $database = $get('DB_DATABASE', 'lanomat_test');
    $username = $get('DB_USERNAME', 'lanomat');
    $password = $get('DB_PASSWORD', 'lanomat');
    $ports = array_values(array_unique([$get('DB_PORT', '5432'), '5435', '5434', '5432']));

    foreach ($ports as $port) {
        try {
            new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s;connect_timeout=1', $host, $port, $database),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (Throwable) {
            continue;
        }

        $_ENV['DB_PORT'] = $_SERVER['DB_PORT'] = $port;

        return;
    }
}

/**
 * A self-contained published recipe: requirements, steps and a launch target.
 *
 * @param  array<string, mixed>  $overrides
 */
function recipeIndexComplete(array $overrides = []): GameRecipe
{
    return GameRecipe::factory()->published()->create($overrides + [
        'requirements' => [
            ['role' => 'iso', 'label' => 'DVD', 'match' => ['game*.iso'], 'required' => true],
        ],
        'steps' => [
            ['id' => 'install', 'title' => 'Install base game', 'os' => 'windows', 'commands' => [], 'verify' => []],
        ],
        'metadata' => [
            'launch_targets' => [
                ['id' => 'flat', 'label' => 'Flat', 'os' => 'windows', 'command' => 'Start-Process "{{target}}/game.exe"'],
            ],
        ],
    ]);
}

function recipeIndexToken(User $user): string
{
    $token = 'lmcp_'.bin2hex(random_bytes(16));

    McpToken::query()->forceCreate([
        'user_id' => $user->id,
        'name' => 'recipe index test',
        'token_hash' => McpCodes::hash($token),
        'abilities' => null,
        'last_used_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    return $token;
}

it('flags every published recipe as installable and launcher supported without hiding the rest', function () {
    recipeIndexComplete([
        'slug' => LauncherSupport::PILOT_SLUG,
        'title' => 'Unreal Tournament 2004',
    ]);
    // Complete, but the install engine is still the UT2004 pilot.
    recipeIndexComplete(['slug' => 'openra', 'title' => 'OpenRA']);
    // Published description only: no requirements, no steps, no launch target.
    GameRecipe::factory()->published()->create(['slug' => 'stub-game', 'title' => 'Stub Game']);

    $response = $this->withToken(recipeIndexToken(User::factory()->create()))
        ->getJson('/api/v1/recipes')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['slug', 'title', 'installable', 'launcher_supported']],
        ]);

    $recipes = collect($response->json('data'))->keyBy('slug');

    expect($recipes)->toHaveCount(3)
        ->and($recipes[LauncherSupport::PILOT_SLUG]['installable'])->toBeTrue()
        ->and($recipes[LauncherSupport::PILOT_SLUG]['launcher_supported'])->toBeTrue()
        ->and($recipes['openra']['installable'])->toBeTrue()
        ->and($recipes['openra']['launcher_supported'])->toBeFalse()
        ->and($recipes['stub-game']['installable'])->toBeFalse()
        ->and($recipes['stub-game']['launcher_supported'])->toBeFalse();
});

it('keeps a published recipe without a launch target out of the installable set', function () {
    recipeIndexComplete([
        'slug' => 'no-launch',
        'title' => 'No Launch',
        'metadata' => [],
    ]);

    $response = $this->withToken(recipeIndexToken(User::factory()->create()))
        ->getJson('/api/v1/recipes')
        ->assertOk();

    expect($response->json('data.0.slug'))->toBe('no-launch')
        ->and($response->json('data.0.installable'))->toBeFalse();
});

it('never leaks another user draft but shows the caller own draft', function () {
    $author = User::factory()->create();
    $stranger = User::factory()->create();

    recipeIndexComplete([
        'slug' => 'foreign-draft',
        'title' => 'Foreign Draft',
        'status' => RecipeStatus::Draft,
        'contributor_user_id' => $author->id,
        'reviewed_by' => null,
        'reviewed_at' => null,
    ]);
    recipeIndexComplete([
        'slug' => 'own-draft',
        'title' => 'Own Draft',
        'status' => RecipeStatus::Draft,
        'contributor_user_id' => $stranger->id,
        'reviewed_by' => null,
        'reviewed_at' => null,
    ]);

    $response = $this->withToken(recipeIndexToken($stranger))
        ->getJson('/api/v1/recipes')
        ->assertOk()
        ->assertJsonMissing(['slug' => 'foreign-draft'])
        ->assertJsonPath('data.0.slug', 'own-draft');

    expect(collect($response->json('data'))->pluck('slug')->all())->toBe(['own-draft']);
});

it('shows every draft to an orga bearer token', function () {
    $orga = User::factory()->orga()->create();

    recipeIndexComplete([
        'slug' => 'foreign-draft',
        'title' => 'Foreign Draft',
        'status' => RecipeStatus::Draft,
        'contributor_user_id' => User::factory()->create()->id,
        'reviewed_by' => null,
        'reviewed_at' => null,
    ]);

    $response = $this->withToken(recipeIndexToken($orga))
        ->getJson('/api/v1/recipes')
        ->assertOk();

    expect(collect($response->json('data'))->pluck('slug')->all())->toContain('foreign-draft');
});

it('decides launcher support with LauncherSupport for the pilot, a browser recipe, a portable and a plain recipe', function () {
    recipeIndexComplete([
        'slug' => LauncherSupport::PILOT_SLUG,
        'title' => 'Unreal Tournament 2004',
    ]);
    recipeIndexComplete([
        'slug' => 'arcade-racer',
        'title' => 'Arcade Racer',
        'metadata' => ['play_url' => 'https://arcade.lan.example/play'],
    ]);
    GameRecipe::factory()->published()->portable()->create([
        'slug' => 'tiny-racer',
        'title' => 'Tiny Racer',
    ]);
    GameRecipe::factory()->published()->create([
        'slug' => 'plain-game',
        'title' => 'Plain Game',
    ]);

    $response = $this->withToken(recipeIndexToken(User::factory()->create()))
        ->getJson('/api/v1/recipes')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['slug', 'launcher_supported', 'in_lineup', 'pull_request']],
        ]);

    $recipes = collect($response->json('data'))->keyBy('slug');

    expect($recipes[LauncherSupport::PILOT_SLUG]['launcher_supported'])->toBeTrue()
        ->and($recipes['arcade-racer']['launcher_supported'])->toBeTrue()
        ->and($recipes['tiny-racer']['launcher_supported'])->toBeTrue()
        ->and($recipes['plain-game']['launcher_supported'])->toBeFalse()
        ->and($recipes['tiny-racer']['in_lineup'])->toBeFalse();
});

it('reports the lineup flag and shows the pull request only to its contributor', function () {
    $author = User::factory()->create();
    $stranger = User::factory()->create();

    $recipe = recipeIndexComplete([
        'slug' => 'own-contribution',
        'title' => 'Own Contribution',
        'status' => RecipeStatus::Published,
        'source' => GameRecipe::SOURCE_CONTRIBUTION,
        'contributor_user_id' => $author->id,
        'in_lineup' => true,
    ]);
    $recipe->forceFill([
        'pr_number' => 42,
        'pr_url' => 'https://github.com/lanomat/recipes/pull/42',
        'pr_branch' => 'recipes/own-contribution',
        'pr_state' => PullRequestState::Open,
    ])->save();

    $own = $this->withToken(recipeIndexToken($author))->getJson('/api/v1/recipes')->assertOk();
    $other = $this->withToken(recipeIndexToken($stranger))->getJson('/api/v1/recipes')->assertOk();

    $ownRow = collect($own->json('data'))->firstWhere('slug', 'own-contribution');
    $otherRow = collect($other->json('data'))->firstWhere('slug', 'own-contribution');

    expect($ownRow['in_lineup'])->toBeTrue()
        ->and($ownRow['pull_request'])->toMatchArray([
            'number' => 42,
            'state' => 'open',
        ])
        ->and($otherRow['in_lineup'])->toBeTrue()
        ->and($otherRow['pull_request'])->toBeNull();
});

it('shows the pull request on the recipe detail only to its contributor', function () {
    $author = User::factory()->create();
    $stranger = User::factory()->create();

    $recipe = recipeIndexComplete([
        'slug' => 'detail-contribution',
        'title' => 'Detail Contribution',
        'status' => RecipeStatus::Published,
        'source' => GameRecipe::SOURCE_CONTRIBUTION,
        'contributor_user_id' => $author->id,
    ]);
    $recipe->forceFill([
        'pr_number' => 7,
        'pr_url' => 'https://github.com/lanomat/recipes/pull/7',
        'pr_branch' => 'recipes/detail-contribution',
        'pr_state' => PullRequestState::Open,
    ])->save();

    $this->withToken(recipeIndexToken($author))
        ->getJson('/api/v1/recipes/detail-contribution')
        ->assertOk()
        ->assertJsonPath('recipe.pull_request.number', 7)
        ->assertJsonPath('recipe.launcher_supported', false)
        ->assertJsonPath('recipe.portable', false);

    $this->withToken(recipeIndexToken($stranger))
        ->getJson('/api/v1/recipes/detail-contribution')
        ->assertOk()
        ->assertJsonPath('recipe.pull_request', null);
});

it('forwards launch targets, portable metadata and a pinned own file from the REST contribution', function () {
    $user = User::factory()->create();
    $event = Event::factory()->registration()->create();
    $file = SharedFile::factory()->create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'original_name' => 'tiny-racer.zip',
    ]);

    $response = $this->withToken(recipeIndexToken($user))
        ->postJson('/api/v1/recipes', [
            'slug' => 'tiny-racer',
            'title' => 'Tiny Racer',
            'summary' => 'Kleine Rennen auf der LAN.',
            'version' => '1.2.3',
            'homepage' => 'https://tiny-racer.lan.example/',
            'license' => 'Freeware; the archive is user-supplied',
            'platforms' => ['windows'],
            'requires' => [[
                'role' => 'base',
                'label' => 'Tiny Racer archive',
                'match' => ['tiny-racer.zip'],
                'required' => true,
                'shared_file_id' => $file->id,
                'os' => 'windows',
                'notes' => 'Unpack the archive into the target folder.',
            ]],
            'steps' => [[
                'id' => 'check-install',
                'title' => 'Unpack and check',
                'os' => 'windows',
                'optional' => false,
                'detail' => 'Unpack the archive into the target folder.',
                'verify' => [['type' => 'file_exists', 'path' => '{{target}}/TinyRacer.exe']],
            ]],
            'launch_targets' => [[
                'id' => 'windows',
                'label' => 'Windows',
                'os' => 'windows',
                'command' => '{{target}}/TinyRacer.exe',
                'shell' => 'cmd',
                'notes' => 'Starts the game directly.',
            ]],
            'metadata' => [
                'portable' => true,
                'genre' => 'racing',
                'players' => '2-8',
                // Not on the contribution allowlist: must never reach the row.
                'launcher_supported' => true,
            ],
        ])
        ->assertCreated()
        ->assertJsonStructure([
            'recipe' => ['slug', 'title', 'status', 'launcher_supported', 'pull_request'],
        ])
        ->assertJsonPath('recipe.slug', 'tiny-racer')
        ->assertJsonPath('recipe.status', 'draft')
        ->assertJsonPath('recipe.launcher_supported', true)
        ->assertJsonPath('recipe.pull_request', null);

    $recipe = GameRecipe::query()->where('slug', 'tiny-racer')->firstOrFail();

    expect($recipe->contributor_user_id)->toBe($user->id)
        ->and($recipe->source_ref)->toBe('api')
        ->and($recipe->version)->toBe('1.2.3')
        ->and($recipe->homepage)->toBe('https://tiny-racer.lan.example/')
        ->and($recipe->metadata['portable'])->toBeTrue()
        ->and($recipe->metadata['genre'])->toBe('racing')
        ->and($recipe->metadata)->not->toHaveKey('launcher_supported')
        ->and($recipe->files()->first()->shared_file_id)->toBe($file->id)
        ->and(app(LauncherSupport::class)->supports($recipe))->toBeTrue()
        ->and(app(LauncherSupport::class)->isPortable($recipe))->toBeTrue();

    expect($response->json('recipe.launcher_supported'))->toBeTrue();
});

it('refuses a shared file from another participant with a validation error', function () {
    $user = User::factory()->create();
    $foreign = SharedFile::factory()->create(['original_name' => 'not-mine.zip']);

    $this->withToken(recipeIndexToken($user))
        ->postJson('/api/v1/recipes', [
            'slug' => 'pinned-foreign',
            'title' => 'Pinned Foreign',
            'platforms' => ['windows'],
            'requires' => [[
                'role' => 'base',
                'label' => 'Archive',
                'match' => ['not-mine.zip'],
                'required' => true,
                'shared_file_id' => $foreign->id,
            ]],
            'steps' => [[
                'id' => 'check-install',
                'title' => 'Unpack',
                'os' => 'windows',
                'verify' => [['type' => 'file_exists', 'path' => '{{target}}/game.exe']],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('requirements.0.shared_file_id');

    expect(GameRecipe::query()->where('slug', 'pinned-foreign')->exists())->toBeFalse();
});

it('filters the avatar catalogue by the current query parameter and by the old q parameter', function () {
    $user = User::factory()->create();
    $recipe = recipeIndexComplete(['slug' => 'avatar-game', 'title' => 'Avatar Game']);

    $recipe->files()->create([
        'role' => 'avatar',
        'label' => 'Alyx',
        'match_patterns' => ['alyx*.vrm'],
        'required' => false,
        'sort' => 0,
    ]);
    $recipe->files()->create([
        'role' => 'avatar',
        'label' => 'Barney',
        'match_patterns' => ['barney*.vrm'],
        'required' => false,
        'sort' => 1,
    ]);

    $token = recipeIndexToken($user);

    $this->withToken($token)
        ->getJson('/api/v1/recipes/avatar-game/avatars?query=alyx')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.label', 'Alyx');

    $this->withToken($token)
        ->getJson('/api/v1/recipes/avatar-game/avatars?q=barney')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.label', 'Barney');
});

it('keeps requirement tags, author, license, notes and os on the REST contribution', function () {
    $user = User::factory()->create();

    $this->withToken(recipeIndexToken($user))
        ->postJson('/api/v1/recipes', [
            'slug' => 'avatar-pack',
            'title' => 'Avatar Pack',
            'platforms' => ['windows'],
            'requires' => [[
                'role' => 'avatar',
                'label' => 'VRM avatars',
                'match' => ['*.vrm'],
                'required' => false,
                'os' => 'windows',
                'notes' => 'Copy the chosen avatars into the avatars folder.',
                'author' => 'Alyx Vance',
                'license' => 'CC-BY',
                'tags' => ['avatar', 'vrm'],
            ]],
            'steps' => [[
                'id' => 'copy-avatars',
                'title' => 'Copy the avatars',
                'os' => 'windows',
                'optional' => false,
                'detail' => 'Copy the chosen avatars into the avatars folder.',
            ]],
        ])
        ->assertCreated();

    $file = GameRecipe::query()->where('slug', 'avatar-pack')->firstOrFail()->files()->firstOrFail();

    expect($file->metadata)->toMatchArray([
        'os' => 'windows',
        'notes' => 'Copy the chosen avatars into the avatars folder.',
        'author' => 'Alyx Vance',
        'license' => 'CC-BY',
        'tags' => ['avatar', 'vrm'],
    ]);
});

it('keeps the manual flag on a requirement that needs no matching file', function () {
    $user = User::factory()->create();

    $this->withToken(recipeIndexToken($user))
        ->postJson('/api/v1/recipes', [
            'slug' => 'manual-requirement',
            'title' => 'Manual Requirement',
            'platforms' => ['windows'],
            'requires' => [[
                'role' => 'tool',
                'label' => 'Mumble',
                'required' => true,
                'manual' => true,
                'notes' => 'Every player installs Mumble from the download page.',
            ]],
            'steps' => [[
                'id' => 'install',
                'title' => 'Install the game',
                'os' => 'windows',
                'optional' => false,
                'detail' => 'Install the game from the LAN archive.',
            ]],
        ])
        ->assertCreated();

    $file = GameRecipe::query()->where('slug', 'manual-requirement')->firstOrFail()->files()->firstOrFail();

    expect($file->metadata['notes'])->toBe('Every player installs Mumble from the download page.');
});
