<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('renders the public server list page with german labels and a ready server\'s mono address', function () {
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    $match = GameMatch::factory()->for($tournament)->create();

    ServerLink::factory()->create([
        'match_id' => $match->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.5', port: 27015, connectString: 'steam://connect/203.0.113.5:27015'),
    ]);

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/servers")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Servers/Index')
            ->where('labels.title', 'Spielserver')
            ->where('servers.0.address', '203.0.113.5')
            ->where('servers.0.status', 'ready')
        );
});

it('404s the server list page for a draft event', function () {
    $event = Event::factory()->draft()->create();

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/servers")
        ->assertNotFound();
});

it('redirects guests to login', function () {
    $event = Event::factory()->live()->create();

    $this->get("/events/{$event->slug}/servers")->assertRedirect(route('login'));
});

it('omits servers belonging to other events\' tournaments', function () {
    $event = Event::factory()->live()->create();
    $otherEvent = Event::factory()->live()->create();

    $tournament = Tournament::factory()->for($otherEvent)->create();
    $match = GameMatch::factory()->for($tournament)->create();

    ServerLink::factory()->create([
        'match_id' => $match->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '198.51.100.9', port: 27015),
    ]);

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/servers")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Servers/Index')
            ->has('servers', 0)
        );
});

it('lists event-level preset servers with query info, and not-responding ones, but hides failed ones', function () {
    $event = Event::factory()->live()->create();
    $game = Game::factory()->create(['name' => "Garry's Mod"]);
    $base = ['event_id' => $event->id, 'game_id' => $game->id, 'title' => 'Sommer TTT', 'join_info' => new JoinInfo(address: '203.0.113.5', port: 27015, connectString: 'steam://connect/203.0.113.5:27015')];

    ServerLink::factory()->create($base + [
        'status' => ServerLinkStatus::Ready,
        'query_info' => ['name' => 'Sommer TTT', 'map' => 'ttt_minecraft_b5', 'players' => 4, 'max_players' => 16],
        'queried_at' => now(),
    ]);
    ServerLink::factory()->create(array_merge($base, ['status' => ServerLinkStatus::NotResponding, 'join_info' => new JoinInfo(address: '203.0.113.5', port: 27016, connectString: '203.0.113.5:27016')]));
    ServerLink::factory()->create(array_merge($base, ['status' => ServerLinkStatus::Provisioning, 'join_info' => new JoinInfo]));
    ServerLink::factory()->create(array_merge($base, ['status' => ServerLinkStatus::Failed]));
    ServerLink::factory()->create(array_merge($base, ['status' => ServerLinkStatus::InstallFailed]));
    ServerLink::factory()->create(['status' => ServerLinkStatus::Provisioning, 'match_id' => null]);

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/servers")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('servers', 3)
            ->where('servers.0.game', "Garry's Mod")
            ->where('servers.0.title', 'Sommer TTT')
            ->where('servers.0.query.map', 'ttt_minecraft_b5')
            ->where('servers.0.query.players', 4)
            ->where('servers.0.query.maxPlayers', 16)
            ->where('servers.0.connectIsLink', true)
            ->where('servers.1.status', 'not_responding')
            ->where('servers.1.connectIsLink', false)
            ->where('servers.1.query', null)
            ->where('servers.2.status', 'provisioning')
        );
});
