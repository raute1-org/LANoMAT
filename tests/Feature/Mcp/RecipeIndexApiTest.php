<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\Mcp\Http\Api\RecipeController;
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
        'slug' => RecipeController::LAUNCHER_PILOT_SLUG,
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
        ->and($recipes[RecipeController::LAUNCHER_PILOT_SLUG]['installable'])->toBeTrue()
        ->and($recipes[RecipeController::LAUNCHER_PILOT_SLUG]['launcher_supported'])->toBeTrue()
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
