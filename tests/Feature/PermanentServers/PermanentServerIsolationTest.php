<?php

use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Actions\DeprovisionServer;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Jobs\CleanupServerJob;
use App\Modules\GameServers\Listeners\CleanupServersOnCompleted;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Testing\FakePelicanClient;
use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->pelican = fakePelican();
    $this->server = $this->pelican->createServer(FakePelicanClient::makeSpec('egg-1'));
    $this->permanent = ServerLink::factory()->permanent(ServerSource::Pelican)->create([
        'pelican_server_id' => $this->server->id,
    ]);
});

it('survives deleting every event, with no call to the panel', function () {
    $event = Event::factory()->create();
    ServerLink::factory()->create(['event_id' => $event->id, 'status' => ServerLinkStatus::Ready]);

    $event->delete();

    // The event-level server cascades away, the permanent one does not.
    expect(ServerLink::query()->count())->toBe(1)
        ->and($this->permanent->fresh())->not->toBeNull()
        ->and($this->permanent->fresh()->status)->toBe(ServerLinkStatus::Ready)
        ->and($this->pelican->deleted)->toBe([]);
});

it('is left alone when a tournament completes', function () {
    Bus::fake();
    $tournament = Tournament::factory()->create();
    $matchLink = ServerLink::factory()->create();
    GameMatch::factory()->for($tournament)->create(['server_link_id' => $matchLink->id]);

    (new CleanupServersOnCompleted)->handle(new TournamentCompleted($tournament));

    Bus::assertDispatched(CleanupServerJob::class, fn ($job) => $job->serverLinkId === $matchLink->id);
    Bus::assertNotDispatched(CleanupServerJob::class, fn ($job) => $job->serverLinkId === $this->permanent->id);
});

it('is not torn down even when a cleanup job or deprovision is aimed at it directly', function () {
    (new CleanupServerJob($this->permanent->id))->handle($this->pelican);
    app(DeprovisionServer::class)->handle($this->permanent);

    expect($this->pelican->deleted)->toBe([])
        ->and($this->permanent->fresh()->status)->toBe(ServerLinkStatus::Ready);
});

it('is not listed on any event\'s server page', function () {
    $event = Event::factory()->live()->create();

    $this->actingAs(App\Models\User::factory()->create())->get("/events/{$event->slug}/servers")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('servers', 0));
});

it('keeps match and event servers out of the permanent list', function () {
    ServerLink::factory()->create(['status' => ServerLinkStatus::Ready]);

    expect(ServerLink::query()->permanent()->pluck('id')->all())->toBe([$this->permanent->id])
        ->and(ServerLink::query()->notPermanent()->count())->toBe(1);
});

it('keeps permanent, source and node out of mass assignment', function () {
    $link = new ServerLink(['permanent' => true, 'source' => 'external', 'pelican_node_id' => '9', 'custom_server_id' => 1, 'query_protocol' => 'a2s']);

    expect($link->permanent ?? false)->toBeFalse()
        ->and($link->getAttributes())->toBe([]);
});
