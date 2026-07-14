<?php

use App\Models\User;
use App\Modules\Teams\Models\Team;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\ReportStatus;
use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;

it('casts tournament format, status, settings and starts_at', function () {
    $tournament = Tournament::factory()->create([
        'format' => TournamentFormat::SingleElimination,
        'status' => TournamentStatus::Enrollment,
        'settings' => ['best_of' => 3],
        'starts_at' => '2026-08-01 10:00:00',
    ]);

    $fresh = $tournament->fresh();

    expect($fresh->format)->toBe(TournamentFormat::SingleElimination)
        ->and($fresh->status)->toBe(TournamentStatus::Enrollment)
        ->and($fresh->settings)->toBe(['best_of' => 3])
        ->and($fresh->starts_at)->toBeInstanceOf(CarbonInterface::class);
});

it('exposes entries and matches relations on tournament', function () {
    $tournament = Tournament::factory()->create();
    TournamentEntry::factory()->for($tournament)->solo()->create();
    GameMatch::factory()->for($tournament)->create();

    expect($tournament->entries)->toHaveCount(1)
        ->and($tournament->matches)->toHaveCount(1);
});

it('casts entry status and roster_snapshot, and defaults lock_version is not on entry', function () {
    $entry = TournamentEntry::factory()->solo()->create([
        'status' => EntryStatus::CheckedIn,
        'roster_snapshot' => ['players' => ['a', 'b']],
    ]);

    $fresh = $entry->fresh();

    expect($fresh->status)->toBe(EntryStatus::CheckedIn)
        ->and($fresh->roster_snapshot)->toBe(['players' => ['a', 'b']]);
});

it('rejects a tournament entry with both team_id and user_id set', function () {
    $tournament = Tournament::factory()->create();
    $team = Team::factory()->create();
    $user = User::factory()->create();

    expect(fn () => TournamentEntry::factory()->for($tournament)->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
    ]))->toThrow(QueryException::class);
});

it('rejects a tournament entry with neither team_id nor user_id set', function () {
    $tournament = Tournament::factory()->create();

    expect(fn () => TournamentEntry::factory()->for($tournament)->create([
        'team_id' => null,
        'user_id' => null,
    ]))->toThrow(QueryException::class);
});

it('exposes tournament, entry1 and entry2 relations on game match, and defaults lock_version to 0', function () {
    $tournament = Tournament::factory()->create();
    $entry1 = TournamentEntry::factory()->for($tournament)->solo()->create();
    $entry2 = TournamentEntry::factory()->for($tournament)->solo()->create();

    $match = GameMatch::factory()->for($tournament)->create([
        'entry1_id' => $entry1->id,
        'entry2_id' => $entry2->id,
        'status' => MatchStatus::Ready,
    ]);

    $fresh = $match->fresh();

    expect($fresh->tournament->is($tournament))->toBeTrue()
        ->and($fresh->entry1->is($entry1))->toBeTrue()
        ->and($fresh->entry2->is($entry2))->toBeTrue()
        ->and($fresh->status)->toBe(MatchStatus::Ready)
        ->and($fresh->lock_version)->toBe(0);
});

it('resolves the German label for tournament, entry, match and report statuses', function () {
    expect(TournamentStatus::Live->label())->toBe('Live')
        ->and(TournamentFormat::SingleElimination->label())->toBe('Einfach-K.-o.-System')
        ->and(EntryStatus::CheckedIn->label())->toBe('Eingecheckt')
        ->and(MatchStatus::Completed->label())->toBe('Abgeschlossen')
        ->and(ReportStatus::Confirmed->label())->toBe('Bestätigt');
});
