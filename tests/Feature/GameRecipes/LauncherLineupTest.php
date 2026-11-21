<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\LauncherLineup;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Server\LanomatServer;
use App\Modules\Mcp\Support\McpCodes;
use App\Modules\Mcp\Tools\RecipesListTool;
use Database\Seeders\LauncherLineupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

const LINEUP = 'unreal-tournament-2004,need-for-speed-underground-2-browser';

beforeEach(function () {
    Config::set('launcher.lineup', LINEUP);
});

function lineupRecipe(string $slug, string $title, string $status = 'published', array $metadata = []): GameRecipe
{
    $browser = isset($metadata['play_url']);

    $attributes = [
        'slug' => $slug,
        'title' => $title,
        'status' => $status,
        'source' => 'bundled',
        'platforms' => ['windows', 'linux', 'macos'],
        'summary' => $title,
        'requirements' => $status === 'published' && ! $browser
            ? [['role' => 'iso', 'label' => 'Disc', 'match' => [$slug.'.iso'], 'required' => true]]
            : [],
        'steps' => [['id' => 'play', 'title' => 'Play', 'os' => 'any', 'commands' => []]],
        'metadata' => $metadata,
    ];

    return GameRecipe::query()->create($attributes);
}

function lineupToken(User $user): string
{
    $token = 'lmcp_'.bin2hex(random_bytes(16));

    McpToken::query()->forceCreate([
        'user_id' => $user->id,
        'name' => 'lineup test',
        'token_hash' => McpCodes::hash($token),
        'abilities' => null,
        'last_used_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    return $token;
}

function lineupUt2004(): GameRecipe
{
    $recipe = lineupRecipe('unreal-tournament-2004', 'Unreal Tournament 2004', metadata: [
        'launch_targets' => [
            ['id' => 'flat', 'label' => 'Flat', 'os' => 'windows', 'command' => 'UT2004.exe'],
        ],
    ]);

    $recipe->files()->create([
        'role' => 'iso',
        'label' => 'Disc',
        'match_patterns' => ['ut2004*.iso'],
        'required' => true,
    ]);

    return $recipe;
}

function lineupBrowser(): GameRecipe
{
    return lineupRecipe(
        'need-for-speed-underground-2-browser',
        'Need for Speed: Underground 2 (Browser)',
        metadata: [
            'play_url' => 'https://nfsu2.xyz/',
            'server' => [
                'name' => 'NFSU2 (Browser)',
                'address' => 'nfsu2.xyz',
                'port' => 443,
                'connect_url' => 'https://nfsu2.xyz/',
                'players' => 'Ein Server · Nickname · Join/Leave',
            ],
        ],
    );
}

it('shows only the lineup on the web library and keeps other published recipes out', function () {
    $user = User::factory()->create();
    lineupUt2004();
    $browser = lineupBrowser();
    lineupRecipe('apocalypse', 'Apocalypse');
    lineupRecipe('azeroth-survivors', 'Azeroth Survivors');

    $this->actingAs($user)
        ->get('/games')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Games/Index')
            ->has('games', 2)
            ->where('games.0.slug', $browser->slug)
            ->where('games.1.slug', 'unreal-tournament-2004'));

    $this->actingAs($user)
        ->get('/games/apocalypse')
        ->assertNotFound();
});

it('keeps a non-lineup draft out of the launcher API while the lineup stays visible', function () {
    $user = User::factory()->create();
    lineupUt2004();
    lineupBrowser();
    lineupRecipe('my-draft', 'My Draft', RecipeStatus::Draft->value);

    $response = $this->withToken(lineupToken($user))
        ->getJson('/api/v1/recipes')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['slug', 'title', 'installable', 'launcher_supported', 'play_url']],
        ]);

    $slugs = collect($response->json('data'))->pluck('slug')->all();

    expect($slugs)->toEqualCanonicalizing([
        'unreal-tournament-2004',
        'need-for-speed-underground-2-browser',
    ]);
});

it('advertises the browser recipe as playable, not installable', function () {
    lineupBrowser();
    $user = User::factory()->create();

    $response = $this->withToken(lineupToken($user))
        ->getJson('/api/v1/recipes')
        ->assertOk();

    $browser = collect($response->json('data'))
        ->firstWhere('slug', 'need-for-speed-underground-2-browser');

    expect($browser)->not->toBeNull()
        ->and($browser['installable'])->toBeFalse()
        ->and($browser['launcher_supported'])->toBeTrue()
        ->and($browser['play_url'])->toBe('https://nfsu2.xyz/');
});

it('renders the browser recipe with one HTTPS server and no installer', function () {
    $user = User::factory()->create();
    lineupBrowser();

    $this->actingAs($user)
        ->get('/games/need-for-speed-underground-2-browser')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Games/Show')
            ->where('game.slug', 'need-for-speed-underground-2-browser')
            ->where('game.installable', false)
            ->where('game.requires_agent', false)
            ->where('game.play_url', 'https://nfsu2.xyz/')
            ->where('game.server_note', 'Ein Server · Nickname · Join/Leave')
            ->has('game.requirements', 0)
            ->has('servers', 1)
            ->where('servers.0.title', 'NFSU2 (Browser)')
            ->where('servers.0.address', 'nfsu2.xyz')
            ->where('servers.0.port', 443)
            ->where('servers.0.connectString', 'https://nfsu2.xyz/')
            ->where('servers.0.connectIsLink', true));
});

it('filters the MCP catalogue to the lineup', function () {
    lineupUt2004();
    lineupBrowser();
    lineupRecipe('ridge-racer-type-4', 'Ridge Racer Type 4');

    LanomatServer::actingAs(User::factory()->create());

    LanomatServer::tool(RecipesListTool::class)
        ->assertSee('unreal-tournament-2004')
        ->assertSee('need-for-speed-underground-2-browser')
        ->assertDontSee('ridge-racer-type-4');
});

it('treats an empty lineup as unrestricted for back-compat', function () {
    Config::set('launcher.lineup', '');

    $lineup = new LauncherLineup;

    expect($lineup->isUnrestricted())->toBeTrue()
        ->and($lineup->allows('apocalypse'))->toBeTrue()
        ->and($lineup->slugs())->toBe([]);
});

it('parses, normalizes and ignores malformed lineup entries', function () {
    Config::set('launcher.lineup', ' Unreal-Tournament-2004 , need-for-speed-underground-2-browser, , Not A Slug ');

    $lineup = new LauncherLineup;

    expect($lineup->slugs())->toBe([
        'unreal-tournament-2004',
        'need-for-speed-underground-2-browser',
    ])->and($lineup->allows('apocalypse'))->toBeFalse();
});

it('registers the browser and native servers once without touching other servers', function () {
    $user = User::factory()->create();

    $this->seed(LauncherLineupSeeder::class);
    $this->seed(LauncherLineupSeeder::class);

    $this->actingAs($user)
        ->get('/servers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('permanent', 2)
            ->where('permanent.0.title', 'NFSU2 (Browser)')
            ->where('permanent.0.address', 'nfsu2.xyz')
            ->where('permanent.0.port', 443)
            ->where('permanent.0.connectString', 'https://nfsu2.xyz/')
            ->where('permanent.1.title', 'NFSU2 (NFS-Online, unser Server)')
            ->where('permanent.1.address', '10.23.3.220')
            ->where('permanent.1.port', 20922));
});
