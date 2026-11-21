<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Domain\InstallHint;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

// The page renders through the blade shell; the built Vite manifest is a
// shared, gitignored artefact that a concurrent build may be rewriting. The
// prop assertions are the point here, so the fake Vite keeps the test
// independent of that build.
beforeEach(function () {
    $this->withoutVite();
});

/**
 * The cross-event server browser at /servers/all: every visible server of the public
 * events plus the permanent servers, grouped by game. Visibility mirrors the
 * event server page (login required, draft events 404/hidden there, so they are
 * out of the aggregate too).
 */
function globalServerLinkFor(Event $event, Game $game, string $address, string $title): ServerLink
{
    $tournament = Tournament::factory()->for($event)->for($game)->create();
    $match = GameMatch::factory()->for($tournament)->create();

    return ServerLink::factory()->create([
        'match_id' => $match->id,
        'status' => ServerLinkStatus::Ready,
        'title' => $title,
        'join_info' => new JoinInfo(address: $address, port: 27015),
    ]);
}

it('serves the browser to any logged-in participant and sends guests to login', function () {
    $this->get('/servers/all')->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get('/servers/all')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Servers/Global')
            ->where('labels.title', 'Serverbrowser')
            ->has('servers', 0)
            ->has('games', 0)
            ->where('filters.active', false)
        );
});

it('aggregates servers of two public events plus the permanent servers, by game', function () {
    $first = Event::factory()->live()->create(['name' => 'Sommer LAN']);
    $second = Event::factory()->live()->create(['name' => 'Winter LAN']);

    $cs2 = Game::factory()->create(['name' => 'Counter-Strike 2']);
    $gmod = Game::factory()->create([
        'name' => "Garry's Mod",
        'install_hint' => new InstallHint(steamUrl: 'steam://install/4000', versionNote: 'Version 13'),
    ]);
    $minecraft = Game::factory()->create(['name' => 'Minecraft']);

    globalServerLinkFor($first, $cs2, '203.0.113.5', 'Turnier-Server');

    // Event-level preset server of the second event (no match, no tournament).
    ServerLink::factory()->create([
        'event_id' => $second->id,
        'game_id' => $gmod->id,
        'title' => 'Winter TTT',
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.6', port: 27015),
        'query_info' => ['name' => 'Winter TTT', 'map' => 'ttt_minecraft_b5', 'players' => 4, 'max_players' => 16],
    ]);

    ServerLink::factory()->permanent()->create([
        'game_id' => $minecraft->id,
        'title' => 'Dauerwelt',
        'join_info' => new JoinInfo(address: '203.0.113.7', port: 25565),
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/servers/all')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Servers/Global')
            ->has('servers', 3)
            ->where('games', ['Counter-Strike 2', "Garry's Mod", 'Minecraft'])
            // event server of the first event
            ->where('servers.0.game', 'Counter-Strike 2')
            ->where('servers.0.title', 'Turnier-Server')
            ->where('servers.0.address', '203.0.113.5')
            ->where('servers.0.event.name', 'Sommer LAN')
            ->where('servers.0.event.slug', $first->slug)
            ->where('servers.0.permanent', false)
            // event-level server of the second event, with its map/players and install hint
            ->where('servers.1.game', "Garry's Mod")
            ->where('servers.1.title', 'Winter TTT')
            ->where('servers.1.query.map', 'ttt_minecraft_b5')
            ->where('servers.1.query.players', 4)
            ->where('servers.1.event.slug', $second->slug)
            ->where('servers.1.installHint.steamUrl', 'steam://install/4000')
            ->where('servers.1.installHint.versionNote', 'Version 13')
            // the permanent server has no event and is flagged as permanent
            ->where('servers.2.game', 'Minecraft')
            ->where('servers.2.permanent', true)
            ->where('servers.2.event', null)
        );
});

it('leaves out draft and archived events and servers that are not visible', function () {
    $live = Event::factory()->live()->create();
    $draft = Event::factory()->draft()->create();
    $archived = Event::factory()->archived()->create();
    $game = Game::factory()->create(['name' => 'Counter-Strike 2']);

    globalServerLinkFor($live, $game, '203.0.113.5', 'Sichtbar');
    globalServerLinkFor($draft, $game, '203.0.113.6', 'Entwurf');
    globalServerLinkFor($archived, $game, '203.0.113.7', 'Archiviert');

    // A created-but-failed server has nothing joinable, like on the event page.
    ServerLink::factory()->create([
        'event_id' => $live->id,
        'game_id' => $game->id,
        'status' => ServerLinkStatus::Failed,
        'join_info' => new JoinInfo(address: '203.0.113.8', port: 27015),
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/servers/all')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('servers', 1)
            ->where('servers.0.title', 'Sichtbar')
        );
});

it('filters by game and free-text search, and ignores an unknown game', function () {
    $event = Event::factory()->live()->create(['name' => 'Sommer LAN']);
    $cs2 = Game::factory()->create(['name' => 'Counter-Strike 2']);
    $gmod = Game::factory()->create(['name' => "Garry's Mod"]);

    globalServerLinkFor($event, $cs2, '203.0.113.5', 'Turnier-Server');
    ServerLink::factory()->create([
        'event_id' => $event->id,
        'game_id' => $gmod->id,
        'title' => 'Sommer TTT',
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.6', port: 27016),
        'query_info' => ['name' => 'Sommer TTT', 'map' => 'ttt_minecraft_b5', 'players' => 2, 'max_players' => 16],
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/servers/all?game='.urlencode("Garry's Mod"))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('servers', 1)
            ->where('servers.0.title', 'Sommer TTT')
            ->where('filters.game', "Garry's Mod")
            ->where('filters.active', true)
            // the game filter still offers every game of the unfiltered list
            ->where('games', ['Counter-Strike 2', "Garry's Mod"])
        );

    // Search matches the name, the map and the event name.
    $this->actingAs($user)
        ->get('/servers/all?q=ttt')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('servers', 1)
            ->where('servers.0.title', 'Sommer TTT')
            ->where('filters.q', 'ttt')
        );

    $this->actingAs($user)
        ->get('/servers/all?q='.urlencode('minecraft_b5'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('servers', 1)
            ->where('servers.0.title', 'Sommer TTT')
        );

    // An unknown game is "all games", not an error and not an empty page.
    $this->actingAs($user)
        ->get('/servers/all?game=Nicht%20vorhanden')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('servers', 2)
            ->where('filters.game', '')
            ->where('filters.active', false)
        );

    // A search without hits keeps the empty state distinguishable from "nothing running".
    $this->actingAs($user)
        ->get('/servers/all?q=ohne%20treffer')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('servers', 0)
            ->where('filters.active', true)
        );
});

it('redirects the old permanent url to the server overview', function () {
    $this->actingAs(User::factory()->create())
        ->get('/servers/permanent')
        ->assertRedirect('/servers');
});
