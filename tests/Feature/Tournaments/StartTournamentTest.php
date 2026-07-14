<?php

use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Domain\Bracket;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Events\TournamentStarted;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('starts an 8-entry single-elimination tournament, persists 7 matches and dispatches TournamentStarted', function () {
    $tournament = Tournament::factory()->checkIn()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(8)->create(['tournament_id' => $tournament->id]);

    // Fake only TournamentStarted (not every event) so Eloquent's own
    // creating/saving lifecycle events - e.g. Event::booted()'s slug
    // generation - are unaffected by the fake.
    Event::fake(TournamentStarted::class);

    $started = app(StartTournament::class)->handle($tournament);

    expect($started->status)->toBe(TournamentStatus::Live)
        ->and(GameMatch::where('tournament_id', $tournament->id)->count())->toBe(7);

    Event::assertDispatched(TournamentStarted::class, fn (TournamentStarted $event) => $event->tournament->is($started));

    $round1 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->get();
    expect($round1)->toHaveCount(4);

    foreach ($round1 as $match) {
        expect($match->status)->toBe(MatchStatus::Ready)
            ->and($match->entry1_id)->not->toBeNull()
            ->and($match->entry2_id)->not->toBeNull();
    }
});

it('rejects starting a tournament that has already gone live', function () {
    $tournament = Tournament::factory()->live()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(4)->create(['tournament_id' => $tournament->id]);

    expect(fn () => app(StartTournament::class)->handle($tournament))
        ->toThrow(TournamentException::class);
});

it('does not create duplicate matches on a double-start attempt', function () {
    $tournament = Tournament::factory()->checkIn()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(4)->create(['tournament_id' => $tournament->id]);

    $started = app(StartTournament::class)->handle($tournament);
    expect(GameMatch::where('tournament_id', $tournament->id)->count())->toBe(3);

    expect(fn () => app(StartTournament::class)->handle($started->fresh()))
        ->toThrow(TournamentException::class);

    expect(GameMatch::where('tournament_id', $tournament->id)->count())->toBe(3);
});

it('leaves no open bye match and fills round 2 with the auto-advanced entrant for 6 entries', function () {
    $tournament = Tournament::factory()->checkIn()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(6)->create(['tournament_id' => $tournament->id]);

    app(StartTournament::class)->handle($tournament);

    // Bracket size for n=6 pads to 8: round 1 has 4 matches, 2 of which are
    // byes (8 - 6 = 2 empty slots). No round-1 match may be left "open" with
    // a bye still sitting in a playable slot — bye matches are either not
    // persisted at all, or persisted as Completed with the real entry
    // already the winner.
    $round1 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->get();

    foreach ($round1 as $match) {
        if ($match->entry1_id === null || $match->entry2_id === null) {
            // A round-1 match with a missing slot must already be resolved
            // (Completed with a winner), never left Pending/Ready.
            expect($match->status)->toBe(MatchStatus::Completed)
                ->and($match->winner_entry_id)->not->toBeNull();
        }
    }

    // Round 2 slots that were fed by a bye match must already hold the real
    // entrant instead of dangling on a pending reference.
    $round2 = GameMatch::where('tournament_id', $tournament->id)->where('round', 2)->get();
    expect($round2)->toHaveCount(2);

    $totalRound2Filled = $round2->sum(fn (GameMatch $m) => ($m->entry1_id !== null ? 1 : 0) + ($m->entry2_id !== null ? 1 : 0));

    // 6 entries -> 2 byes -> 2 auto-advanced entrants must appear already
    // seated in round 2 (in addition to whichever round-1 winners have not
    // been decided yet, which is none at persist time - so exactly the 2
    // byes are pre-filled).
    expect($totalRound2Filled)->toBe(2);
});

it('shuffles solo entries into ad-hoc teams when auto_team is enabled', function () {
    $tournament = Tournament::factory()->checkIn()->singleElim()->create([
        'team_size' => 2,
        'settings' => ['auto_team' => true],
    ]);
    TournamentEntry::factory()->solo()->checkedIn()->count(8)->create(['tournament_id' => $tournament->id]);

    app(StartTournament::class)->handle($tournament);

    $teamEntries = TournamentEntry::where('tournament_id', $tournament->id)
        ->whereNotNull('team_id')
        ->get();

    expect($teamEntries)->toHaveCount(4);

    foreach ($teamEntries as $entry) {
        expect($entry->roster_snapshot)->toHaveCount(2);
    }

    // Original solo entries must no longer be the ones seeded into matches -
    // matches must reference the new ad-hoc team entries.
    $seededEntryIds = GameMatch::where('tournament_id', $tournament->id)
        ->where('round', 1)
        ->get()
        ->flatMap(fn (GameMatch $m) => [$m->entry1_id, $m->entry2_id])
        ->filter()
        ->unique()
        ->values();

    expect($seededEntryIds->sort()->values()->all())
        ->toBe($teamEntries->pluck('id')->sort()->values()->all());
});

it('respects manually assigned seeds before randomizing the rest', function () {
    $tournament = Tournament::factory()->checkIn()->singleElim()->create();
    $seededTop = TournamentEntry::factory()->checkedIn()->create(['tournament_id' => $tournament->id, 'seed' => 1]);
    $seededSecond = TournamentEntry::factory()->checkedIn()->create(['tournament_id' => $tournament->id, 'seed' => 2]);
    TournamentEntry::factory()->checkedIn()->count(2)->create(['tournament_id' => $tournament->id]);

    app(StartTournament::class)->handle($tournament);

    // Standard bracket seed order for size 4 is [1, 4, 3, 2]: seed 1 and
    // seed 2 must land in different round-1 matches (they only meet in the
    // final), proving the manual seeds were honored rather than shuffled.
    $matches = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->get();

    $matchOf = function (int $entryId) use ($matches): int {
        foreach ($matches as $match) {
            if ($match->entry1_id === $entryId || $match->entry2_id === $entryId) {
                return $match->id;
            }
        }

        throw new RuntimeException("Entry {$entryId} not seeded into any round-1 match.");
    };

    expect($matchOf($seededTop->id))->not->toBe($matchOf($seededSecond->id));
});

it('links next_match_id and loser_match_id for a double-elimination bracket', function () {
    $tournament = Tournament::factory()->checkIn()->doubleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(4)->create(['tournament_id' => $tournament->id]);

    app(StartTournament::class)->handle($tournament);

    $matches = GameMatch::where('tournament_id', $tournament->id)->get();

    // Every match except the very final (grand-final reset, GF2) must route
    // forward via next_match_id.
    $withoutNext = $matches->filter(fn (GameMatch $m) => $m->next_match_id === null);
    expect($withoutNext)->toHaveCount(1);

    // Winners-bracket round-1 matches must route their losers into the
    // losers bracket.
    $wbRound1 = $matches->where('bracket', Bracket::Winners->value)->where('round', 1);
    foreach ($wbRound1 as $match) {
        expect($match->loser_match_id)->not->toBeNull();

        $loserMatch = $matches->firstWhere('id', $match->loser_match_id);
        expect($loserMatch->bracket)->toBe(Bracket::Losers->value);
    }

    // The finals bracket must contain exactly 2 matches (GF1 + GF2).
    expect($matches->where('bracket', Bracket::Finals->value))->toHaveCount(2);
});

it('throws for an unsupported double-elimination entry count', function () {
    $tournament = Tournament::factory()->checkIn()->doubleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(5)->create(['tournament_id' => $tournament->id]);

    expect(fn () => app(StartTournament::class)->handle($tournament))
        ->toThrow(TournamentException::class);

    expect(GameMatch::where('tournament_id', $tournament->id)->count())->toBe(0)
        ->and($tournament->fresh()->status)->toBe(TournamentStatus::CheckIn);
});

it('succeeds for each supported double-elimination entry count', function (int $count) {
    $tournament = Tournament::factory()->checkIn()->doubleElim()->create();
    TournamentEntry::factory()->checkedIn()->count($count)->create(['tournament_id' => $tournament->id]);

    $started = app(StartTournament::class)->handle($tournament);

    expect($started->status)->toBe(TournamentStatus::Live);
})->with([4, 6, 8, 16]);
