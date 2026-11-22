<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Contracts\GameInstallLinks;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\ServerListProjection;
use App\Modules\Mcp\Actions\DecideDeviceAuthorization;
use App\Modules\Mcp\Actions\ExchangeDeviceCode;
use App\Modules\Mcp\Actions\StartDeviceAuthorization;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The install block of the server projection: the published recipe that
 * installs a server's game, the game page it belongs to, the one-click
 * launcher join when the launcher can install it, and whether the game's
 * files are on the LAN right now (`lanReady`).
 */
beforeEach(function () {
    $this->withoutVite();
    // Unrestricted lineup unless a test curates it; the lineup filter itself
    // is covered by LauncherLineupTest.
    Config::set('launcher.lineup', '');
    Config::set('launcher.dist_path', base_path('clients-dist/__missing-for-tests__'));
});

function installLinksGame(string $slug, string $name): Game
{
    return Game::factory()->create(['slug' => $slug, 'name' => $name]);
}

/** A ready server of that game, reachable at `203.0.113.5:7777`. */
function installLinksServer(Game $game, ?Event $event = null, array $overrides = []): ServerLink
{
    return ServerLink::factory()->create($overrides + [
        'event_id' => $event?->id,
        'game_id' => $game->id,
        'title' => $game->name.' Server',
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.5', port: 7777),
        'query_info' => ['name' => $game->name, 'map' => 'arena', 'players' => 2, 'max_players' => 16],
        'queried_at' => now(),
    ]);
}

/** A short-lived agent token for the bearer API (same flow as the Mcp tests). */
function installLinksToken(User $user): string
{
    $pair = app(StartDeviceAuthorization::class)->handle('install-links-test');

    app(DecideDeviceAuthorization::class)->handle($user, $pair['user_code'], true);

    return app(ExchangeDeviceCode::class)->handle($pair['device_code'])['access_token'];
}

/** @return array<string, mixed>|null */
function installLinksBlock(?string $slug, ?string $name, ?string $address = '203.0.113.5', ?int $port = 7777, ?string $joinUrl = null): ?array
{
    return app(GameInstallLinks::class)->forServer($slug, $name, $address, $port, $joinUrl);
}

/**
 * A published portable recipe with the one required base archive row a shared
 * game has: `shared_file_id` pins the contributed upload, the pattern still
 * matches an upload of that name, and `url` is the author's external fallback.
 */
function installLinksPortable(string $slug = 'tiny-racer', string $title = 'Tiny Racer', ?string $archive = null, ?string $url = null): GameRecipe
{
    $archive ??= $slug.'-portable.zip';

    $recipe = GameRecipe::factory()->published()->portable($archive)->create([
        'slug' => $slug,
        'title' => $title,
    ]);

    $recipe->files()->create([
        'role' => 'base',
        'label' => 'Portable archive',
        'match_patterns' => [$archive],
        'required' => true,
        'url' => $url,
    ]);

    return $recipe;
}

/** An approved upload on the LAN file host, the way a shared archive arrives. */
function installLinksUpload(Event $event, string $name): SharedFile
{
    return SharedFile::factory()->approved()->create([
        'event_id' => $event->id,
        'original_name' => $name,
    ]);
}

/** @return array<string, mixed>|null the install block of the tiny-racer game */
function installLinksTinyRacer(): ?array
{
    return installLinksBlock('tiny-racer', 'Tiny Racer');
}

it('finds the recipe by the exact game slug', function () {
    GameRecipe::factory()->published()->create([
        'slug' => 'tiny-racer',
        'title' => 'Tiny Racer',
    ]);

    // The name alone would never match the recipe's terms: the slug is the ground truth.
    $install = installLinksBlock('tiny-racer', 'Some Unrelated Name');

    expect($install)->toMatchArray([
        'recipeSlug' => 'tiny-racer',
        'title' => 'Tiny Racer',
        'pageUrl' => '/games/tiny-racer',
        'launcherSupported' => false,
        'launcherJoinUrl' => null,
    ]);
});

it('finds the recipe by an alias or the title', function () {
    GameRecipe::factory()->published()->create([
        'slug' => 'arcade-racer',
        'title' => 'Arcade Racer',
        'aliases' => ['ar', 'racer'],
    ]);

    // Alias contained in the game name.
    expect(installLinksBlock('whatever', 'Arcade Racer Deluxe')['recipeSlug'] ?? null)->toBe('arcade-racer')
        // Alias equals the game slug ("racer" is a term, "racer" is the slug).
        ->and(installLinksBlock('racer', null)['recipeSlug'] ?? null)->toBe('arcade-racer')
        // The title is a search term too.
        ->and(installLinksBlock(null, 'Arcade Racer')['recipeSlug'] ?? null)->toBe('arcade-racer');
});

it('prefers the exact slug over another recipe whose alias matches', function () {
    // Sorted first by title, so an iteration-order pick would choose the alias recipe.
    GameRecipe::factory()->published()->create([
        'slug' => 'alias-racer',
        'title' => 'Aardvark Alias',
        'aliases' => ['tiny-racer'],
    ]);
    GameRecipe::factory()->published()->create([
        'slug' => 'tiny-racer',
        'title' => 'Zebra Racer',
    ]);

    expect(installLinksBlock('tiny-racer', 'Tiny Racer')['recipeSlug'] ?? null)->toBe('tiny-racer');
});

it('answers nothing without a game identity', function () {
    GameRecipe::factory()->published()->create(['slug' => 'tiny-racer', 'title' => 'Tiny Racer']);

    expect(installLinksBlock(null, null))->toBeNull()
        ->and(installLinksBlock('', ''))->toBeNull();
});

it('ignores draft, archived and out-of-lineup recipes', function () {
    installLinksGame('tiny-racer', 'Tiny Racer');
    installLinksGame('mega-racer', 'Mega Racer');
    installLinksGame('zulu-racer', 'Zulu Racer');

    GameRecipe::factory()->draft()->create(['slug' => 'tiny-racer', 'title' => 'Tiny Racer']);
    GameRecipe::factory()->archived()->create(['slug' => 'mega-racer', 'title' => 'Mega Racer']);
    GameRecipe::factory()->published()->create(['slug' => 'zulu-racer', 'title' => 'Zulu Racer']);

    // A curated lineup hides the published but unflagged recipe.
    Config::set('launcher.lineup', 'unreal-tournament-2004');

    expect(installLinksBlock('tiny-racer', 'Tiny Racer'))->toBeNull()
        ->and(installLinksBlock('mega-racer', 'Mega Racer'))->toBeNull()
        ->and(installLinksBlock('zulu-racer', 'Zulu Racer'))->toBeNull();
});

it('finds a recipe the orga put on the lineup in the panel', function () {
    installLinksGame('tiny-racer', 'Tiny Racer');
    GameRecipe::factory()->published()->inLineup()->create(['slug' => 'tiny-racer', 'title' => 'Tiny Racer']);

    Config::set('launcher.lineup', 'unreal-tournament-2004');

    expect(installLinksBlock('tiny-racer', 'Tiny Racer')['recipeSlug'] ?? null)->toBe('tiny-racer');
});

it('builds the address form for UT2004', function () {
    GameRecipe::factory()->published()->create([
        'slug' => 'unreal-tournament-2004',
        'title' => 'Unreal Tournament 2004',
    ]);

    $install = installLinksBlock('unreal-tournament-2004', 'Unreal Tournament 2004');

    expect($install)->toMatchArray([
        'launcherSupported' => true,
        'launcherJoinUrl' => 'lanomat://join/unreal-tournament-2004?address='.rawurlencode('203.0.113.5:7777'),
    ]);

    // IPv6 is bracketed, exactly like the launcher's own host:port form.
    expect(installLinksBlock('unreal-tournament-2004', null, '2001:db8::5', 7777)['launcherJoinUrl'] ?? null)
        ->toBe('lanomat://join/unreal-tournament-2004?address='.rawurlencode('[2001:db8::5]:7777'));
});

it('builds the join-url form for a portable shared recipe', function () {
    GameRecipe::factory()->published()->portable()->create([
        'slug' => 'tiny-racer',
        'title' => 'Tiny Racer',
    ]);

    $install = installLinksBlock('tiny-racer', 'Tiny Racer', '203.0.113.6', 27015, 'steam://connect/203.0.113.6:27015');

    expect($install)->toMatchArray([
        'launcherSupported' => true,
        'launcherJoinUrl' => 'lanomat://join/tiny-racer?url='.rawurlencode('steam://connect/203.0.113.6:27015'),
    ]);
});

it('offers no launcher join for an unsupported recipe or a server with nothing to join', function () {
    // Published, but the launcher cannot install it (no pilot/portable/play_url).
    GameRecipe::factory()->published()->create(['slug' => 'tiny-racer', 'title' => 'Tiny Racer']);

    expect(installLinksBlock('tiny-racer', 'Tiny Racer'))->toMatchArray([
        'launcherSupported' => false,
        'launcherJoinUrl' => null,
    ]);

    // UT2004 is supported, but a server without address, port and join URL has nothing to join.
    GameRecipe::factory()->published()->create(['slug' => 'unreal-tournament-2004', 'title' => 'Unreal Tournament 2004']);

    expect(installLinksBlock('unreal-tournament-2004', 'Unreal Tournament 2004', null, null, null))->toMatchArray([
        'launcherSupported' => true,
        'launcherJoinUrl' => null,
    ]);
});

it('is lan-ready when the required archive is pinned and approved on the LAN', function () {
    $event = Event::factory()->live()->create();
    $recipe = installLinksPortable();
    $upload = installLinksUpload($event, 'tiny-racer-portable.zip');

    $recipe->files()->first()->update(['shared_file_id' => $upload->id]);

    expect(installLinksTinyRacer()['lanReady'] ?? null)->toBeTrue();
});

it('is lan-ready when an approved upload matches the archive pattern', function () {
    $event = Event::factory()->live()->create();
    installLinksPortable();
    installLinksUpload($event, 'tiny-racer-portable.zip');

    expect(installLinksTinyRacer()['lanReady'] ?? null)->toBeTrue();
});

it('is lan-ready when the required archive only has an external download', function () {
    installLinksPortable(url: 'https://lan.example/tiny-racer-portable.zip');

    // Nothing on the LAN, but the recipe names a download a player can follow.
    expect(installLinksTinyRacer()['lanReady'] ?? null)->toBeTrue();
});

it('is not lan-ready when the pinned archive was deleted', function () {
    $event = Event::factory()->live()->create();
    $recipe = installLinksPortable();
    $upload = installLinksUpload($event, 'tiny-racer-portable.zip');

    $recipe->files()->first()->update(['shared_file_id' => $upload->id]);
    $upload->delete();

    // `shared_file_id` is null-on-delete: the row still promises an archive that is gone.
    expect($recipe->files()->first()?->shared_file_id)->toBeNull()
        ->and(installLinksTinyRacer()['lanReady'] ?? null)->toBeFalse();
});

it('is not lan-ready while the pinned archive still waits for approval', function () {
    $event = Event::factory()->live()->create();
    $recipe = installLinksPortable();
    $pending = SharedFile::factory()->create([
        'event_id' => $event->id,
        'original_name' => 'tiny-racer-portable.zip',
    ]);

    $recipe->files()->first()->update(['shared_file_id' => $pending->id]);

    expect(installLinksTinyRacer()['lanReady'] ?? null)->toBeFalse();
});

it('is not lan-ready when the pinned archive was rejected', function () {
    $event = Event::factory()->live()->create();
    $recipe = installLinksPortable();
    $rejected = SharedFile::factory()->rejected()->create([
        'event_id' => $event->id,
        'original_name' => 'tiny-racer-portable.zip',
    ]);

    $recipe->files()->first()->update(['shared_file_id' => $rejected->id]);

    expect(installLinksTinyRacer()['lanReady'] ?? null)->toBeFalse();
});

it('is not lan-ready when the only upload belongs to a draft event', function () {
    $draft = Event::factory()->draft()->create();
    installLinksPortable();
    installLinksUpload($draft, 'tiny-racer-portable.zip');

    expect(installLinksTinyRacer()['lanReady'] ?? null)->toBeFalse();
});

it('is not lan-ready when a required base archive has no file row at all', function () {
    // The authored requirement without its file row: a contribution whose rows never arrived.
    GameRecipe::factory()->published()->portable()->create(['slug' => 'tiny-racer', 'title' => 'Tiny Racer']);

    expect(installLinksTinyRacer()['lanReady'] ?? null)->toBeFalse();
});

it('is not lan-ready when nothing on the LAN matches the required base archive', function () {
    $event = Event::factory()->live()->create();
    installLinksPortable();
    installLinksUpload($event, 'some-other-archive.zip');

    expect(installLinksTinyRacer()['lanReady'] ?? null)->toBeFalse();
});

it('never lets an optional or a non-base requirement block readiness', function () {
    $event = Event::factory()->live()->create();
    $recipe = installLinksPortable();
    installLinksUpload($event, 'tiny-racer-portable.zip');

    // Extras and patches: missing, but the base game still installs.
    $recipe->files()->create(['role' => 'base', 'label' => 'Extra maps', 'match_patterns' => ['maps.zip'], 'required' => false]);
    $recipe->files()->create(['role' => 'patch', 'label' => 'Patch', 'match_patterns' => ['patch.zip'], 'required' => true]);
    $recipe->files()->create(['role' => 'avatar', 'label' => 'Avatar', 'match_patterns' => ['*.vrm'], 'required' => true]);

    expect(installLinksTinyRacer()['lanReady'] ?? null)->toBeTrue();
});

it('keeps the launcher join while the game is not on the LAN', function () {
    installLinksPortable();

    $install = installLinksBlock('tiny-racer', 'Tiny Racer', '203.0.113.6', 27015, 'steam://connect/203.0.113.6:27015');

    // Whoever already has the game joins with one click; only a fresh install is blocked.
    expect($install)->toMatchArray([
        'launcherSupported' => true,
        'lanReady' => false,
        'launcherJoinUrl' => 'lanomat://join/tiny-racer?url='.rawurlencode('steam://connect/203.0.113.6:27015'),
    ]);
});

it('carries the install block on the event server page', function () {
    $event = Event::factory()->live()->create();
    GameRecipe::factory()->published()->create(['slug' => 'unreal-tournament-2004', 'title' => 'Unreal Tournament 2004']);
    $game = installLinksGame('unreal-tournament-2004', 'Unreal Tournament 2004');
    installLinksServer($game, $event);

    $this->actingAs(User::factory()->create())
        ->get('/servers?event='.$event->slug)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('servers', 1)
            ->where('servers.0.install.recipeSlug', 'unreal-tournament-2004')
            ->where('servers.0.install.title', 'Unreal Tournament 2004')
            ->where('servers.0.install.pageUrl', '/games/unreal-tournament-2004')
            ->where('servers.0.install.launcherSupported', true)
            ->where('servers.0.install.launcherJoinUrl', 'lanomat://join/unreal-tournament-2004?address='.rawurlencode('203.0.113.5:7777')));
});

it('carries the install block in the global server browser', function () {
    $event = Event::factory()->live()->create();
    GameRecipe::factory()->published()->create(['slug' => 'unreal-tournament-2004', 'title' => 'Unreal Tournament 2004']);
    $game = installLinksGame('unreal-tournament-2004', 'Unreal Tournament 2004');
    installLinksServer($game, $event);

    $this->actingAs(User::factory()->create())
        ->get('/servers/all')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('servers', 1)
            ->where('servers.0.install.recipeSlug', 'unreal-tournament-2004')
            ->where('servers.0.install.launcherJoinUrl', 'lanomat://join/unreal-tournament-2004?address='.rawurlencode('203.0.113.5:7777')));
});

it('carries the install block in the launcher API', function () {
    $event = Event::factory()->live()->create();
    GameRecipe::factory()->published()->create(['slug' => 'unreal-tournament-2004', 'title' => 'Unreal Tournament 2004']);
    $game = installLinksGame('unreal-tournament-2004', 'Unreal Tournament 2004');
    installLinksServer($game, $event);

    $this->withToken(installLinksToken(User::factory()->create()))
        ->getJson('/api/v1/servers')
        ->assertOk()
        ->assertJsonPath('servers.0.install.recipeSlug', 'unreal-tournament-2004')
        ->assertJsonPath('servers.0.install.pageUrl', '/games/unreal-tournament-2004')
        ->assertJsonPath('servers.0.install.launcherSupported', true)
        ->assertJsonPath('servers.0.install.launcherJoinUrl', 'lanomat://join/unreal-tournament-2004?address='.rawurlencode('203.0.113.5:7777'));
});

it('carries the install block on the home page permanent servers and in now playing', function () {
    GameRecipe::factory()->published()->create(['slug' => 'unreal-tournament-2004', 'title' => 'Unreal Tournament 2004']);
    $game = installLinksGame('unreal-tournament-2004', 'Unreal Tournament 2004');

    // Upcoming state: the short permanent rows.
    Event::factory()->announced()->create();
    ServerLink::factory()->permanent()->create([
        'game_id' => $game->id,
        'title' => 'UT2004 Dauerwelt',
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.5', port: 7777),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('permanent.0.install.recipeSlug', 'unreal-tournament-2004')
            ->where('permanent.0.install.launcherJoinUrl', 'lanomat://join/unreal-tournament-2004?address='.rawurlencode('203.0.113.5:7777')));

    // Live state: the now-playing join row keeps the block it picked.
    ServerLink::query()->delete();

    $event = Event::factory()->live()->create();
    installLinksServer($game, $event);

    $version = $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true'])
        ->get('/')
        ->headers->get('X-Inertia-Version');

    $props = $this->actingAs($user)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'Now',
        'X-Inertia-Partial-Data' => 'nowPlaying',
    ])->get('/')->assertOk()->json('props');

    expect($props['nowPlaying'][0]['join']['install']['recipeSlug'])->toBe('unreal-tournament-2004')
        ->and($props['nowPlaying'][0]['join']['install']['launcherJoinUrl'])
        ->toBe('lanomat://join/unreal-tournament-2004?address='.rawurlencode('203.0.113.5:7777'));
});

it('keeps the install key null while the recipe module is off', function () {
    $event = Event::factory()->live()->create();
    GameRecipe::factory()->published()->create(['slug' => 'unreal-tournament-2004', 'title' => 'Unreal Tournament 2004']);
    $game = installLinksGame('unreal-tournament-2004', 'Unreal Tournament 2004');
    installLinksServer($game, $event);

    // Swapped after the arrangement: the request runs with GameRecipes off, so
    // the contract resolves to the null implementation.
    app()->instance(Modules::class, new Modules('full', [Module::GameRecipes->key() => false]));

    $this->actingAs(User::factory()->create())
        ->get('/servers?event='.$event->slug)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('servers', 1)
            ->has('servers.0.install')
            ->where('servers.0.install', null));
});

it('tells the server browser whether the game can be installed fresh', function () {
    $event = Event::factory()->live()->create();
    $recipe = installLinksPortable();
    $upload = installLinksUpload($event, 'tiny-racer-portable.zip');
    $recipe->files()->first()->update(['shared_file_id' => $upload->id]);
    $game = installLinksGame('tiny-racer', 'Tiny Racer');
    installLinksServer($game, $event);

    $user = User::factory()->create();
    // The row's own join string (the factory's steam link), unchanged by readiness.
    $joinUrl = 'lanomat://join/tiny-racer?url='.rawurlencode('steam://connect/203.0.113.5:7777');

    $this->actingAs($user)->get('/servers/all')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('servers', 1)
            ->where('servers.0.install.lanReady', true)
            ->where('servers.0.install.launcherJoinUrl', $joinUrl));

    // The archive leaves the LAN, the one-click join stays: existing installs still play.
    $upload->delete();

    $this->actingAs($user)->get('/servers/all')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('servers', 1)
            ->where('servers.0.install.lanReady', false)
            ->where('servers.0.install.launcherSupported', true)
            ->where('servers.0.install.launcherJoinUrl', $joinUrl));
});

it('loads the catalogue, its file rows and the LAN files once for a browser of ten servers', function () {
    $event = Event::factory()->live()->create();

    $recipe = installLinksPortable('game-0', 'Game 0');
    $upload = installLinksUpload($event, 'game-0-portable.zip');
    $recipe->files()->first()->update(['shared_file_id' => $upload->id]);

    foreach (range(0, 9) as $index) {
        $game = installLinksGame('game-'.$index, 'Game '.$index);
        installLinksServer($game, $event);
    }

    $counts = ['game_recipes' => 0, 'game_recipe_files' => 0, 'shared_files' => 0];

    DB::listen(function ($query) use (&$counts): void {
        foreach (array_keys($counts) as $table) {
            if (str_contains($query->sql, $table)) {
                $counts[$table]++;
            }
        }
    });

    $rows = ServerListProjection::forGlobalBrowser();

    // One read per table, no matter how many servers the browser lists.
    expect($rows)->toHaveCount(10)
        ->and($counts)->toBe([
            'game_recipes' => 1,
            'game_recipe_files' => 1,
            'shared_files' => 1,
        ])
        ->and($rows[0]['install']['recipeSlug'] ?? null)->toBe('game-0')
        ->and($rows[0]['install']['lanReady'] ?? null)->toBeTrue();
});

it('offers the one-click launcher join in the server browser for a shared portable game', function () {
    Config::set('launcher.lineup', 'unreal-tournament-2004');

    GameRecipe::factory()->published()->inLineup()->portable()->create([
        'slug' => 'tiny-racer',
        'title' => 'Tiny Racer',
    ]);

    $game = installLinksGame('tiny-racer', 'Tiny Racer');
    $event = Event::factory()->live()->create();
    installLinksServer($game, $event);

    $this->actingAs(User::factory()->create())
        ->get('/servers/all')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('servers', 1)
            ->where('servers.0.install.launcherJoinUrl', 'lanomat://join/tiny-racer?url='.rawurlencode('steam://connect/203.0.113.5:7777')));
});
