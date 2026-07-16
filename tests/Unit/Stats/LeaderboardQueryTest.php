<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Stats\Support\BadgeCalculator;
use App\Modules\Stats\Support\LeaderboardQuery;
use App\Modules\Teams\Models\Team;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Marks a match as completed with the given winning entry — status/
 * winner_entry_id are Action-only fields (see GameMatch), so tests force
 * them directly, same as BroadcastTest does for Tournament::winner_entry_id.
 */
function completeMatch(GameMatch $match, TournamentEntry $winner): GameMatch
{
    $match->forceFill([
        'status' => MatchStatus::Completed,
        'winner_entry_id' => $winner->id,
    ])->save();

    return $match->fresh();
}

it('aggregates wins, tournament wins and participations across events for a solo user entrant', function () {
    $user = User::factory()->create(['name' => 'Alice']);

    $eventA = Event::factory()->live()->create();
    $tournamentA = Tournament::factory()->for($eventA)->create();
    $entryA = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournamentA->id, 'user_id' => $user->id]);
    $opponentA = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournamentA->id]);
    $matchA = GameMatch::factory()->create(['tournament_id' => $tournamentA->id, 'entry1_id' => $entryA->id, 'entry2_id' => $opponentA->id]);
    completeMatch($matchA, $entryA);
    $tournamentA->forceFill(['status' => TournamentStatus::Finished, 'winner_entry_id' => $entryA->id])->save();

    $eventB = Event::factory()->live()->create();
    $tournamentB = Tournament::factory()->for($eventB)->create();
    $entryB = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournamentB->id, 'user_id' => $user->id]);
    $opponentB = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournamentB->id]);
    $matchB = GameMatch::factory()->create(['tournament_id' => $tournamentB->id, 'entry1_id' => $entryB->id, 'entry2_id' => $opponentB->id]);
    completeMatch($matchB, $opponentB); // Alice loses this one

    $rows = LeaderboardQuery::topEntrants();

    $alice = collect($rows)->firstWhere('name', 'Alice');

    expect($alice)->not->toBeNull()
        ->and($alice['wins'])->toBe(1)
        ->and($alice['tournamentWins'])->toBe(1)
        ->and($alice['participations'])->toBe(2)
        ->and($alice['podiums'])->toBe(1)
        ->and($alice['type'])->toBe('user');
});

it('aggregates wins for a team entrant without merging it with any user', function () {
    $team = Team::factory()->create(['name' => 'Rocket Raccoons']);
    $owner = User::factory()->create(['name' => 'TeamOwner']);
    $team->forceFill(['owner_id' => $owner->id])->save();

    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    $teamEntry = TournamentEntry::factory()->team()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id]);
    $soloEntry = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournament->id, 'user_id' => $owner->id]);

    $match = GameMatch::factory()->create(['tournament_id' => $tournament->id, 'entry1_id' => $teamEntry->id, 'entry2_id' => $soloEntry->id]);
    completeMatch($match, $teamEntry);

    $rows = LeaderboardQuery::topEntrants();

    $teamRow = collect($rows)->firstWhere('name', 'Rocket Raccoons');
    $ownerRow = collect($rows)->firstWhere('name', 'TeamOwner');

    expect($teamRow)->not->toBeNull()
        ->and($teamRow['type'])->toBe('team')
        ->and($teamRow['wins'])->toBe(1)
        // The owner's solo entry lost its only match — 0 wins, not merged
        // with the team's win despite sharing the same person.
        ->and($ownerRow['wins'])->toBe(0)
        ->and($ownerRow['participations'])->toBe(1);
});

it('podiums currently aliases tournamentWins (champion-only; no runner-up rule yet)', function () {
    // There is no persisted runner-up/placement data yet, so `podiums`
    // cannot distinguish 2nd/3rd place from champions — it is currently
    // just an alias of `tournamentWins`. A distinct podium rule is
    // deferred to the roadmap's "Stats-Kür".
    $user = User::factory()->create(['name' => 'Bob']);
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    $entry = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);
    $opponent = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournament->id]);
    $match = GameMatch::factory()->create(['tournament_id' => $tournament->id, 'entry1_id' => $entry->id, 'entry2_id' => $opponent->id]);
    completeMatch($match, $entry);
    $tournament->forceFill(['status' => TournamentStatus::Finished, 'winner_entry_id' => $entry->id])->save();

    $rows = LeaderboardQuery::topEntrants();
    $bob = collect($rows)->firstWhere('name', 'Bob');

    expect($bob['podiums'])->toBe(1);
});

it('respects the limit parameter', function () {
    foreach (range(1, 5) as $i) {
        $user = User::factory()->create();
        $event = Event::factory()->live()->create();
        $tournament = Tournament::factory()->for($event)->create();
        $entry = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);
        $opponent = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournament->id]);
        $match = GameMatch::factory()->create(['tournament_id' => $tournament->id, 'entry1_id' => $entry->id, 'entry2_id' => $opponent->id]);
        completeMatch($match, $entry);
    }

    $rows = LeaderboardQuery::topEntrants(limit: 3);

    expect($rows)->toHaveCount(3);
});

it('grants the hattrick badge for 3 match wins within a single tournament', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    $entry = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

    foreach (range(1, 3) as $i) {
        $opponent = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournament->id]);
        $match = GameMatch::factory()->create(['tournament_id' => $tournament->id, 'entry1_id' => $entry->id, 'entry2_id' => $opponent->id, 'round' => $i]);
        completeMatch($match, $entry);
    }

    $badges = BadgeCalculator::for($user->id, 'user');

    expect($badges)->toContain('hattrick')
        ->and($badges)->toContain('first_win');
});

it('does not grant the hattrick badge when the 3 wins are spread across different tournaments', function () {
    $user = User::factory()->create();

    foreach (range(1, 3) as $i) {
        $event = Event::factory()->live()->create();
        $tournament = Tournament::factory()->for($event)->create();
        $entry = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);
        $opponent = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournament->id]);
        $match = GameMatch::factory()->create(['tournament_id' => $tournament->id, 'entry1_id' => $entry->id, 'entry2_id' => $opponent->id]);
        completeMatch($match, $entry);
    }

    $badges = BadgeCalculator::for($user->id, 'user');

    expect($badges)->not->toContain('hattrick')
        ->and($badges)->toContain('first_win')
        ->and($badges)->toContain('veteran');
});

it('grants the veteran badge only from 3 distinct events participated onward', function () {
    $user = User::factory()->create();

    foreach (range(1, 2) as $i) {
        $event = Event::factory()->live()->create();
        $tournament = Tournament::factory()->for($event)->create();
        TournamentEntry::factory()->solo()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);
    }

    expect(BadgeCalculator::for($user->id, 'user'))->not->toContain('veteran');

    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    TournamentEntry::factory()->solo()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

    expect(BadgeCalculator::for($user->id, 'user'))->toContain('veteran');
});

it('does not grant first_win when the competitor has never won a match', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    $entry = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);
    $opponent = TournamentEntry::factory()->solo()->create(['tournament_id' => $tournament->id]);
    $match = GameMatch::factory()->create(['tournament_id' => $tournament->id, 'entry1_id' => $entry->id, 'entry2_id' => $opponent->id]);
    completeMatch($match, $opponent);

    expect(BadgeCalculator::for($user->id, 'user'))->not->toContain('first_win');
});
