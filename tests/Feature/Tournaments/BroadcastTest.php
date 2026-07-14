<?php

use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Events\MatchCompleted;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Events\TournamentStarted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('implements ShouldBroadcast on all four tournament domain events', function () {
    expect(new MatchReady(GameMatch::factory()->make(['id' => 1])))->toBeInstanceOf(ShouldBroadcast::class)
        ->and(new MatchCompleted(GameMatch::factory()->make(['id' => 1])))->toBeInstanceOf(ShouldBroadcast::class)
        ->and(new TournamentStarted(Tournament::factory()->make(['id' => 1])))->toBeInstanceOf(ShouldBroadcast::class)
        ->and(new TournamentCompleted(Tournament::factory()->make(['id' => 1])))->toBeInstanceOf(ShouldBroadcast::class);
});

it('keeps ShouldDispatchAfterCommit on all four tournament events', function () {
    expect(new MatchReady(GameMatch::factory()->make(['id' => 1])))->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and(new MatchCompleted(GameMatch::factory()->make(['id' => 1])))->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and(new TournamentCompleted(Tournament::factory()->make(['id' => 1])))->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and(new TournamentStarted(Tournament::factory()->make(['id' => 1])))->toBeInstanceOf(ShouldDispatchAfterCommit::class);
});

it('broadcasts MatchReady on the tournament channel with a lean payload', function () {
    $tournament = Tournament::factory()->create();
    $match = GameMatch::factory()->create([
        'tournament_id' => $tournament->id,
        'status' => MatchStatus::Ready,
    ]);

    $event = new MatchReady($match);
    $channel = $event->broadcastOn();

    expect($channel)->toBeInstanceOf(Channel::class)
        ->and($channel->name)->toBe('tournament.'.$tournament->id);

    expect($event->broadcastWith())->toBe([
        'tournament_id' => $tournament->id,
        'match_id' => $match->id,
        'status' => MatchStatus::Ready->value,
    ]);

    expect($event->broadcastAs())->toBeString()->not->toBeEmpty();
});

it('broadcasts MatchCompleted on the tournament channel with a lean payload including the winner', function () {
    $tournament = Tournament::factory()->create();
    $match = GameMatch::factory()->create([
        'tournament_id' => $tournament->id,
        'status' => MatchStatus::Completed,
        'score1' => 2,
        'score2' => 1,
    ]);

    $event = new MatchCompleted($match);
    $channel = $event->broadcastOn();

    expect($channel)->toBeInstanceOf(Channel::class)
        ->and($channel->name)->toBe('tournament.'.$tournament->id);

    expect($event->broadcastWith())->toBe([
        'tournament_id' => $tournament->id,
        'match_id' => $match->id,
        'status' => MatchStatus::Completed->value,
        'winner_entry_id' => $match->winner_entry_id,
    ]);

    expect($event->broadcastAs())->toBeString()->not->toBeEmpty();
});

it('broadcasts TournamentStarted on the tournament channel with a lean payload', function () {
    $tournament = Tournament::factory()->create(['status' => TournamentStatus::Live]);

    $event = new TournamentStarted($tournament);
    $channel = $event->broadcastOn();

    expect($channel)->toBeInstanceOf(Channel::class)
        ->and($channel->name)->toBe('tournament.'.$tournament->id);

    expect($event->broadcastWith())->toBe([
        'tournament_id' => $tournament->id,
        'status' => TournamentStatus::Live->value,
    ]);

    expect($event->broadcastAs())->toBeString()->not->toBeEmpty();
});

it('broadcasts TournamentCompleted on the tournament channel with a lean payload including the champion', function () {
    $tournament = Tournament::factory()->create();
    $champion = TournamentEntry::factory()->create(['tournament_id' => $tournament->id]);
    // status/winner_entry_id are intentionally not mass-assignable (they're
    // set only via Actions), so force them directly for this test fixture.
    $tournament->forceFill(['status' => TournamentStatus::Finished, 'winner_entry_id' => $champion->id])->save();
    $tournament = $tournament->fresh();

    $event = new TournamentCompleted($tournament);
    $channel = $event->broadcastOn();

    expect($channel)->toBeInstanceOf(Channel::class)
        ->and($channel->name)->toBe('tournament.'.$tournament->id);

    expect($event->broadcastWith())->toBe([
        'tournament_id' => $tournament->id,
        'status' => TournamentStatus::Finished->value,
        'winner_entry_id' => $champion->id,
    ]);

    expect($event->broadcastAs())->toBeString()->not->toBeEmpty();
});

it('gives each event a distinct, stable broadcast name', function () {
    $tournament = Tournament::factory()->create();
    $match = GameMatch::factory()->create(['tournament_id' => $tournament->id]);

    $names = [
        (new MatchReady($match))->broadcastAs(),
        (new MatchCompleted($match))->broadcastAs(),
        (new TournamentStarted($tournament))->broadcastAs(),
        (new TournamentCompleted($tournament))->broadcastAs(),
    ];

    expect($names)->toEqual(array_unique($names));
});
