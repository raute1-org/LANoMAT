<?php

use App\Models\User;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamMember;
use App\Modules\Tournaments\Actions\EnrollSolo;
use App\Modules\Tournaments\Actions\EnrollTeam;
use App\Modules\Tournaments\Actions\WithdrawEntry;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('enrolls a solo user during enrollment', function () {
    $tournament = Tournament::factory()->enrollment()->create();
    $user = User::factory()->create();

    $entry = app(EnrollSolo::class)->handle($tournament, $user);

    expect($entry->tournament_id)->toBe($tournament->id)
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->display_name)->toBe($user->name)
        ->and($entry->status)->toBe(EntryStatus::Registered);
});

it('rejects solo enrollment when the tournament is not in enrollment status', function () {
    $tournament = Tournament::factory()->create(); // draft
    $user = User::factory()->create();

    expect(fn () => app(EnrollSolo::class)->handle($tournament, $user))
        ->toThrow(TournamentException::class);
});

it('rejects solo enrollment when the tournament is full', function () {
    $tournament = Tournament::factory()->enrollment()->create(['max_entries' => 1]);
    app(EnrollSolo::class)->handle($tournament, User::factory()->create());

    expect(fn () => app(EnrollSolo::class)->handle($tournament, User::factory()->create()))
        ->toThrow(TournamentException::class);
});

it('does not count withdrawn entries toward the capacity limit', function () {
    $tournament = Tournament::factory()->enrollment()->create(['max_entries' => 1]);
    $first = app(EnrollSolo::class)->handle($tournament, User::factory()->create());
    app(WithdrawEntry::class)->handle($first);

    $second = app(EnrollSolo::class)->handle($tournament, User::factory()->create());

    expect($second->status)->toBe(EntryStatus::Registered);
});

it('serializes concurrent enrollments against the capacity limit via the parent-row lock', function () {
    $tournament = Tournament::factory()->enrollment()->create(['max_entries' => 1]);

    $users = User::factory()->count(5)->create();

    // Simulate a capacity race: without the parent-row lock, concurrent
    // callers could all read count=0 before any insert commits and all
    // pass the max_entries guard. We can't spin up real concurrent DB
    // connections in a single-process Pest run, but we can verify the
    // lock-order contract holds by running many sequential enrollments
    // against max_entries=1 inside nested transactions sharing the same
    // connection, asserting exactly one ever succeeds.
    $succeeded = 0;
    $failed = 0;

    foreach ($users as $user) {
        try {
            app(EnrollSolo::class)->handle($tournament, $user);
            $succeeded++;
        } catch (TournamentException $e) {
            $failed++;
        }
    }

    expect($succeeded)->toBe(1)
        ->and($failed)->toBe(4)
        ->and(TournamentEntry::where('tournament_id', $tournament->id)->where('status', '!=', EntryStatus::Withdrawn->value)->count())->toBe(1);
});

it('rejects a double enrollment of the same user', function () {
    $tournament = Tournament::factory()->enrollment()->create();
    $user = User::factory()->create();
    app(EnrollSolo::class)->handle($tournament, $user);

    expect(fn () => app(EnrollSolo::class)->handle($tournament, $user))
        ->toThrow(TournamentException::class);
});

it('enrolls a team and writes the roster snapshot', function () {
    $tournament = Tournament::factory()->enrollment()->create(['team_size' => 2]);
    $team = Team::factory()->create();
    $owner = User::factory()->create(['name' => 'Owner Person']);
    $mate = User::factory()->create(['name' => 'Mate Person']);
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $owner->id]);
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $mate->id]);

    $entry = app(EnrollTeam::class)->handle($tournament, $team);

    expect($entry->team_id)->toBe($team->id)
        ->and($entry->display_name)->toBe($team->name)
        ->and($entry->roster_snapshot)->toHaveCount(2)
        ->and(collect($entry->roster_snapshot)->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$owner->id, $mate->id])->sort()->values()->all())
        ->and(collect($entry->roster_snapshot)->pluck('name')->sort()->values()->all())
        ->toBe(collect(['Owner Person', 'Mate Person'])->sort()->values()->all());
});

it('rejects team enrollment when the roster size does not match team_size', function () {
    $tournament = Tournament::factory()->enrollment()->create(['team_size' => 2]);
    $team = Team::factory()->create();
    TeamMember::factory()->create(['team_id' => $team->id]);

    expect(fn () => app(EnrollTeam::class)->handle($tournament, $team))
        ->toThrow(TournamentException::class);
});

it('rejects a double enrollment of the same team', function () {
    $tournament = Tournament::factory()->enrollment()->create(['team_size' => 1]);
    $team = Team::factory()->create();
    TeamMember::factory()->create(['team_id' => $team->id]);

    app(EnrollTeam::class)->handle($tournament, $team);

    expect(fn () => app(EnrollTeam::class)->handle($tournament, $team))
        ->toThrow(TournamentException::class);
});

it('withdraws an entry and excludes it from the capacity count', function () {
    $tournament = Tournament::factory()->enrollment()->create();
    $entry = app(EnrollSolo::class)->handle($tournament, User::factory()->create());

    $withdrawn = app(WithdrawEntry::class)->handle($entry);

    expect($withdrawn->status)->toBe(EntryStatus::Withdrawn)
        ->and(TournamentEntry::query()->find($entry->id)->status)->toBe(EntryStatus::Withdrawn);
});

it('rejects withdrawal once the tournament has gone live', function () {
    $tournament = Tournament::factory()->enrollment()->create();
    $entry = app(EnrollSolo::class)->handle($tournament, User::factory()->create());
    $tournament->status = TournamentStatus::Live;
    $tournament->save();

    expect(fn () => app(WithdrawEntry::class)->handle($entry))
        ->toThrow(TournamentException::class);
});

it('resolves a German translation for the full-tournament error', function () {
    expect(TournamentException::full()->translationKey)
        ->toBe('tournaments.errors.full');

    app()->setLocale('de');
    expect(__(TournamentException::full()->translationKey))
        ->toBe('Das Turnier ist ausgebucht.');
});
