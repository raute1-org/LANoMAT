<?php

use App\Modules\Events\Models\Event;
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

    $this->get("/events/{$event->slug}/servers")
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

    $this->get("/events/{$event->slug}/servers")
        ->assertNotFound();
});

it('is reachable without a logged-in user', function () {
    $event = Event::factory()->live()->create();

    expect(auth()->check())->toBeFalse();

    $this->get("/events/{$event->slug}/servers")->assertOk();
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

    $this->get("/events/{$event->slug}/servers")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Servers/Index')
            ->has('servers', 0)
        );
});
