<?php

use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Events\MatchScoreUpdated;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function cs2ServerLink(): ServerLink
{
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    $match = GameMatch::factory()->for($tournament)->create([
        'status' => MatchStatus::Ready,
    ]);

    $link = ServerLink::factory()->create([
        'match_id' => $match->id,
    ]);

    $match->forceFill(['server_link_id' => $link->id])->save();

    return $link->fresh();
}

it('updates the live score and dispatches MatchScoreUpdated for a valid MatchZy round_end payload', function () {
    EventFacade::fake([MatchScoreUpdated::class]);

    $link = cs2ServerLink();

    $response = $this->postJson("/api/telemetry/cs2/{$link->id}", [
        'event' => 'round_end',
        'matchid' => 'abc123',
        'map_number' => 0,
        'round_number' => 5,
        'team1' => ['name' => 'Team Alpha', 'score' => 3],
        'team2' => ['name' => 'Team Beta', 'score' => 2],
    ], ['Authorization' => 'Bearer '.$link->telemetry_token]);

    $response->assertNoContent();

    $match = $link->match()->first()->fresh();

    expect($match->score1)->toBe(3)
        ->and($match->score2)->toBe(2);

    EventFacade::assertDispatched(MatchScoreUpdated::class, function (MatchScoreUpdated $dispatched) use ($match) {
        return $dispatched->match->id === $match->id
            && $dispatched->team1 === 'Team Alpha'
            && $dispatched->team2 === 'Team Beta'
            && $dispatched->score1 === 3
            && $dispatched->score2 === 2
            && $dispatched->round === 5;
    });
});

it('surfaces the live telemetry score on the tournament page with its German label', function () {
    $link = cs2ServerLink();

    $this->postJson("/api/telemetry/cs2/{$link->id}", [
        'event' => 'round_end',
        'round_number' => 5,
        'team1' => ['name' => 'Team Alpha', 'score' => 3],
        'team2' => ['name' => 'Team Beta', 'score' => 2],
    ], ['Authorization' => 'Bearer '.$link->telemetry_token]);

    $match = $link->match()->first()->fresh();

    $this->get("/tournaments/{$match->tournament_id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tournaments/Show')
            ->where('matches.0.score1', 3)
            ->where('matches.0.score2', 2)
            // i18n gate: the German live-score label is exposed to the page.
            ->where('liveScoreLabels.label', 'Live-Stand')
        );
});

it('rejects a request with a missing token', function () {
    EventFacade::fake([MatchScoreUpdated::class]);

    $link = cs2ServerLink();

    $response = $this->postJson("/api/telemetry/cs2/{$link->id}", [
        'event' => 'round_end',
        'team1' => ['name' => 'Team Alpha', 'score' => 1],
        'team2' => ['name' => 'Team Beta', 'score' => 0],
    ]);

    $response->assertUnauthorized();

    EventFacade::assertNotDispatched(MatchScoreUpdated::class);
    expect($link->match()->first()->fresh()->score1)->toBeNull();
});

it('rejects a request with a wrong token', function () {
    EventFacade::fake([MatchScoreUpdated::class]);

    $link = cs2ServerLink();

    $response = $this->postJson("/api/telemetry/cs2/{$link->id}", [
        'event' => 'round_end',
        'team1' => ['name' => 'Team Alpha', 'score' => 1],
        'team2' => ['name' => 'Team Beta', 'score' => 0],
    ], ['Authorization' => 'Bearer wrong-token']);

    $response->assertForbidden();

    EventFacade::assertNotDispatched(MatchScoreUpdated::class);
});

it('ignores an unknown event type gracefully without dispatching', function () {
    EventFacade::fake([MatchScoreUpdated::class]);

    $link = cs2ServerLink();

    $response = $this->postJson("/api/telemetry/cs2/{$link->id}", [
        'event' => 'player_death',
        'attacker' => 'Foo',
        'victim' => 'Bar',
    ], ['Authorization' => 'Bearer '.$link->telemetry_token]);

    $response->assertNoContent();

    EventFacade::assertNotDispatched(MatchScoreUpdated::class);
    expect($link->match()->first()->fresh()->score1)->toBeNull();
});

it('ignores a malformed payload gracefully without crashing', function () {
    EventFacade::fake([MatchScoreUpdated::class]);

    $link = cs2ServerLink();

    $response = $this->postJson("/api/telemetry/cs2/{$link->id}", [
        'not' => 'a recognizable shape',
    ], ['Authorization' => 'Bearer '.$link->telemetry_token]);

    $response->assertStatus(422);

    EventFacade::assertNotDispatched(MatchScoreUpdated::class);
});

it('returns 404 for an unknown server link id', function () {
    $response = $this->postJson('/api/telemetry/cs2/999999', [
        'event' => 'round_end',
        'team1' => ['name' => 'A', 'score' => 1],
        'team2' => ['name' => 'B', 'score' => 0],
    ], ['Authorization' => 'Bearer whatever']);

    $response->assertNotFound();
});

it('returns 204 gracefully when the ServerLink has no associated match', function () {
    EventFacade::fake([MatchScoreUpdated::class]);

    $link = ServerLink::factory()->create(['match_id' => null]);

    $response = $this->postJson("/api/telemetry/cs2/{$link->id}", [
        'event' => 'round_end',
        'team1' => ['name' => 'A', 'score' => 1],
        'team2' => ['name' => 'B', 'score' => 0],
    ], ['Authorization' => 'Bearer '.$link->telemetry_token]);

    $response->assertNoContent();
    EventFacade::assertNotDispatched(MatchScoreUpdated::class);
});
