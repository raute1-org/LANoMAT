<?php

use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\GameServers\Listeners\UpdateMatchSurfacesOnServerReady;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function matchWithReadyServerLink(): GameMatch
{
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    $match = GameMatch::factory()->for($tournament)->create();

    $link = ServerLink::factory()->create([
        'match_id' => $match->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.10', port: 27015),
    ]);

    // server_link_id is deliberately not fillable (see GameMatch), mirroring
    // ProvisionMatchServerJob's forceFill.
    $match->forceFill(['server_link_id' => $link->id])->save();
    $match->update(['discord_channels' => ['text_channel_id' => 'fake-channel-1']]);

    return $match->fresh();
}

it('surfaces a Ready match ServerLink in the tournament-page match DTO', function () {
    $match = matchWithReadyServerLink();

    $this->get("/tournaments/{$match->tournament_id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tournaments/Show')
            ->has('matches.0.server')
            ->where('matches.0.server.address', '203.0.113.10')
            ->where('matches.0.server.port', 27015)
            ->where('matches.0.server.status', 'ready')
            // i18n gate: the German server-status label is exposed to the page.
            ->where('serverLinkStatusLabels.ready', 'Bereit')
        );
});

it('exposes null server on the match DTO when no ServerLink exists', function () {
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    GameMatch::factory()->for($tournament)->create();

    $this->get("/tournaments/{$tournament->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tournaments/Show')
            ->where('matches.0.server', null)
        );
});

it('(re)sends the Discord match embed containing the connect string when a ServerLink becomes Ready', function () {
    $fake = fakeDiscord();

    $match = matchWithReadyServerLink();
    $link = $match->serverLink;

    app(UpdateMatchSurfacesOnServerReady::class)->handle(new ServerLinkUpdated($link));

    $fake->assertMessageSent('fake-channel-1');
    $message = collect($fake->messages)->firstWhere('channelId', 'fake-channel-1');
    expect($message['embeds'][0]['description'])->toContain('steam://connect/203.0.113.10:27015');
});

it('does not resend the Discord embed twice for the same ServerLink (outbox dedup)', function () {
    $fake = fakeDiscord();

    $match = matchWithReadyServerLink();
    $link = $match->serverLink;

    $listener = app(UpdateMatchSurfacesOnServerReady::class);
    $listener->handle(new ServerLinkUpdated($link));
    $listener->handle(new ServerLinkUpdated($link));

    $sentToChannel = collect($fake->messages)->where('channelId', 'fake-channel-1')->count();
    expect($sentToChannel)->toBe(1);
});

it('does nothing when the match has no Discord channel yet', function () {
    $fake = fakeDiscord();

    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    $match = GameMatch::factory()->for($tournament)->create(['discord_channels' => null]);

    $link = ServerLink::factory()->create([
        'match_id' => $match->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.10', port: 27015),
    ]);
    $match->forceFill(['server_link_id' => $link->id])->save();

    app(UpdateMatchSurfacesOnServerReady::class)->handle(new ServerLinkUpdated($link));

    $fake->assertNothingSent();
});
