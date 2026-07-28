<?php

use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Support\BracketMatchProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks a completed match with a single entrant as a bye', function () {
    $entry = TournamentEntry::factory()->create();

    $match = GameMatch::factory()->create([
        'tournament_id' => $entry->tournament_id,
        'status' => MatchStatus::Completed,
        'entry1_id' => $entry->id,
        'entry2_id' => null,
        'winner_entry_id' => $entry->id,
        'score1' => null,
        'score2' => null,
    ]);

    expect(BracketMatchProjection::fromMatch($match->fresh())['isBye'])->toBeTrue();
});

it('does not mark a two-entrant completed match as a bye', function () {
    $entry1 = TournamentEntry::factory()->create();
    $entry2 = TournamentEntry::factory()->create(['tournament_id' => $entry1->tournament_id]);

    $match = GameMatch::factory()->create([
        'tournament_id' => $entry1->tournament_id,
        'status' => MatchStatus::Completed,
        'entry1_id' => $entry1->id,
        'entry2_id' => $entry2->id,
        'winner_entry_id' => $entry1->id,
        'score1' => 2,
        'score2' => 1,
    ]);

    expect(BracketMatchProjection::fromMatch($match->fresh())['isBye'])->toBeFalse();
});

it('does not mark a pending match with an unfilled slot as a bye', function () {
    $entry = TournamentEntry::factory()->create();

    $match = GameMatch::factory()->create([
        'tournament_id' => $entry->tournament_id,
        'status' => MatchStatus::Pending,
        'entry1_id' => $entry->id,
        'entry2_id' => null,
    ]);

    expect(BracketMatchProjection::fromMatch($match->fresh())['isBye'])->toBeFalse();
});

it('does not mark a phantom match (both winners-bracket feeders were byes) as a bye', function () {
    // A losers-bracket match becomes unplayable when both of its winners-
    // bracket feeder matches were themselves byes: no real entrant ever
    // drops into either slot, so BracketPersister resolves it straight to
    // Completed with both entry slots null and no winner. This must NOT be
    // reported as a bye — Task 5 renders byes as "a real entrant advanced
    // unopposed", which is false here: nobody advanced from this match at
    // all.
    $match = GameMatch::factory()->create([
        'status' => MatchStatus::Completed,
        'entry1_id' => null,
        'entry2_id' => null,
        'winner_entry_id' => null,
        'score1' => null,
        'score2' => null,
    ]);

    expect(BracketMatchProjection::fromMatch($match->fresh())['isBye'])->toBeFalse();
});
