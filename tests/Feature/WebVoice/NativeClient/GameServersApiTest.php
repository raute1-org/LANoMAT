<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\ServerListProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/PlayHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
    $this->event = playEvent();
    $this->me = User::factory()->create();
    $this->other = User::factory()->create();
    $this->third = User::factory()->create();
    $this->headers = playBearer($this->me);
});

function playMatchServer(object $match, ServerLinkStatus $status = ServerLinkStatus::Ready, ?JoinInfo $join = null, string $title = 'Matchserver'): ServerLink
{
    return ServerLink::factory()->create([
        'match_id' => $match->id,
        'title' => $title,
        'status' => $status,
        'join_info' => $join ?? new JoinInfo(address: '203.0.113.5', port: 27015),
    ]);
}

it('requires a valid bearer token on both server routes', function () {
    $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/servers")->assertUnauthorized();
    $this->getJson('/api/voice-client/v1/servers')->assertUnauthorized();
    $this->getJson('/api/voice-client/v1/servers', ['Authorization' => 'Bearer garbage'])->assertUnauthorized();

    $this->getJson('/api/voice-client/v1/servers', playBearer($this->me))->assertOk();
});

it('lists my own match server with a steam join link and what to copy', function () {
    $match = playMatch($this->event, $this->me, $this->other);
    playMatchServer($match);

    $response = $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/servers", $this->headers)->assertOk();

    $server = $response->json('servers.0');
    expect($response->json('servers'))->toHaveCount(1)
        ->and($server)->toMatchArray([
            'title' => 'Matchserver',
            'status' => 'ready',
            'address' => '203.0.113.5',
            'port' => 27015,
            'connectString' => 'steam://connect/203.0.113.5:27015',
            'connectIsLink' => true,
            'joinUrl' => 'steam://connect/203.0.113.5:27015',
            'copyText' => '203.0.113.5:27015',
        ]);
});

it('does not list the match server of other players, but does for their opponent and for orga', function () {
    $match = playMatch($this->event, $this->other, $this->third);
    playMatchServer($match);

    $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/servers", $this->headers)
        ->assertOk()->assertJsonCount(0, 'servers');

    $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/servers", playBearer($this->third))
        ->assertOk()->assertJsonCount(1, 'servers');

    $orga = User::factory()->create(['role' => Role::Orga]);
    $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/servers", playBearer($orga))
        ->assertOk()->assertJsonCount(1, 'servers');
});

it('lists event level servers to every logged-in user, like the web list', function () {
    $game = Game::factory()->create(['name' => 'Minecraft']);
    ServerLink::factory()->create([
        'event_id' => $this->event->id,
        'game_id' => $game->id,
        'title' => 'Survival',
        'description' => 'Whitelist off',
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.9', port: 25565, connectString: '203.0.113.9:25565'),
        'query_info' => ['name' => 'Survival', 'map' => 'world', 'players' => 3, 'max_players' => 20],
        'queried_at' => now(),
    ]);

    $server = $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/servers", $this->headers)
        ->assertOk()->json('servers.0');

    expect($server)->toMatchArray([
        'title' => 'Survival',
        'game' => 'Minecraft',
        'description' => 'Whitelist off',
        'players' => 3,
        'maxPlayers' => 20,
        'map' => 'world',
        'connectIsLink' => false,
        'joinUrl' => null,
        'copyText' => '203.0.113.9:25565',
    ])->and($server)->toHaveKeys(['installHint', 'address', 'port', 'connectString']);
});

it('shows the password only where the web connect string carries it', function () {
    $match = playMatch($this->event, $this->me, $this->other);
    playMatchServer($match, join: new JoinInfo(address: '203.0.113.5', port: 27015, password: 'hunter2-secret'));

    $response = $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/servers", $this->headers)->assertOk();

    // The stored password is never copied into its own field or the steam link.
    expect($response->getContent())->not->toContain('hunter2-secret')
        ->and($response->json('servers.0.copyText'))->toBe('203.0.113.5:27015');

    ServerLink::query()->delete();
    playMatchServer($match, join: new JoinInfo(address: '203.0.113.5', port: 27015, password: 'hunter2-secret', connectString: 'connect 203.0.113.5:27015; password hunter2-secret'));

    $server = $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/servers", $this->headers)->json('servers.0');

    expect($server['copyText'])->toBe('connect 203.0.113.5:27015; password hunter2-secret')
        ->and($server['joinUrl'])->toBeNull()
        // The web page shows exactly this connect string to this player.
        ->and($server['connectString'])->toBe(ServerListProjection::forEvent($this->event)[0]['connectString']);
});

it('offers no join or copy for a server that is still starting', function () {
    $match = playMatch($this->event, $this->me, $this->other);
    ServerLink::factory()->create(['event_id' => $this->event->id, 'title' => 'Startet', 'status' => ServerLinkStatus::Provisioning, 'join_info' => new JoinInfo(address: '203.0.113.7', port: 1)]);
    playMatchServer($match, ServerLinkStatus::Failed);

    $servers = $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/servers", $this->headers)->json('servers');

    // A failed server is not listed (same as the web page); the starting one has nothing to join yet.
    expect($servers)->toHaveCount(1)
        ->and($servers[0]['status'])->toBe('provisioning')
        ->and($servers[0]['joinUrl'])->toBeNull()
        ->and($servers[0]['copyText'])->toBeNull();
});

it('answers 404 for a draft event and for an unknown one', function () {
    $draft = Event::factory()->draft()->create();

    $this->getJson("/api/voice-client/v1/events/{$draft->slug}/servers", $this->headers)->assertNotFound();
    $this->getJson('/api/voice-client/v1/events/no-such-event/servers', $this->headers)->assertNotFound();
});

it('does not leak another event\'s servers', function () {
    $otherEvent = Event::factory()->live()->create();
    ServerLink::factory()->create(['event_id' => $otherEvent->id, 'title' => 'Fremd', 'status' => ServerLinkStatus::Ready, 'join_info' => new JoinInfo(address: '203.0.113.8', port: 2)]);

    $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/servers", $this->headers)
        ->assertOk()->assertJsonCount(0, 'servers');
});

it('lists permanent servers for any logged-in user, including offline ones, with the web list\'s rules', function () {
    $up = ServerLink::factory()->permanent()->create(['title' => 'Minecraft Dauerwelt']);
    $down = ServerLink::factory()->permanent()->create(['title' => 'UT2004', 'status' => ServerLinkStatus::Offline]);
    ServerLink::factory()->permanent()->create(['title' => 'Kaputt', 'status' => ServerLinkStatus::Failed]);
    // An event server is never part of the permanent list.
    ServerLink::factory()->create(['event_id' => $this->event->id, 'status' => ServerLinkStatus::Ready, 'join_info' => new JoinInfo(address: '203.0.113.9', port: 3)]);

    foreach ([User::factory()->create(['role' => Role::Participant]), $this->me] as $user) {
        $servers = $this->getJson('/api/voice-client/v1/servers', playBearer($user))->assertOk()->json('servers');

        expect(collect($servers)->pluck('title')->all())->toBe(['Minecraft Dauerwelt', 'UT2004'])
            ->and(collect($servers)->pluck('id')->all())->toBe(collect(ServerListProjection::forPermanent())->pluck('id')->all())
            ->and(collect($servers)->firstWhere('id', $down->id)['joinUrl'])->toBeNull()
            ->and(collect($servers)->firstWhere('id', $down->id)['copyText'])->toBeNull()
            ->and(collect($servers)->firstWhere('id', $up->id)['copyText'])->toBe('203.0.113.10:25565');
    }
});

it('does not accept the bearer token on the web server pages', function () {
    $this->getJson('/servers', $this->headers)->assertUnauthorized();
    $this->get("/events/{$this->event->slug}/servers", $this->headers)->assertRedirect('/login');
});
