<?php

use App\Models\User;
use App\Modules\Tournaments\Actions\CheckInEntry;
use App\Modules\Tournaments\Actions\CloseCheckin;
use App\Modules\Tournaments\Actions\EnrollSolo;
use App\Modules\Tournaments\Actions\OpenCheckin;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects check-in outside the check-in window', function () {
    $tournament = Tournament::factory()->enrollment()->create();
    $entry = app(EnrollSolo::class)->handle($tournament, User::factory()->create());

    expect(fn () => app(CheckInEntry::class)->handle($entry))
        ->toThrow(TournamentException::class);
});

it('checks in an entry inside the check-in window', function () {
    $tournament = Tournament::factory()->checkIn()->create([
        'checkin_opens_at' => now()->subMinutes(10),
        'checkin_closes_at' => now()->addMinutes(10),
    ]);
    $entry = TournamentEntry::factory()->create([
        'tournament_id' => $tournament->id,
    ]);

    $checkedIn = app(CheckInEntry::class)->handle($entry);

    expect($checkedIn->status)->toBe(EntryStatus::CheckedIn)
        ->and($checkedIn->checked_in_at)->not->toBeNull();
});

it('rejects check-in after the window has closed', function () {
    $tournament = Tournament::factory()->checkIn()->create([
        'checkin_opens_at' => now()->subHours(2),
        'checkin_closes_at' => now()->subHour(),
    ]);
    $entry = TournamentEntry::factory()->create([
        'tournament_id' => $tournament->id,
    ]);

    expect(fn () => app(CheckInEntry::class)->handle($entry))
        ->toThrow(TournamentException::class);
});

it('opens check-in via the action', function () {
    $tournament = Tournament::factory()->enrollment()->create();

    $updated = app(OpenCheckin::class)->handle($tournament);

    expect($updated->status)->toBe(TournamentStatus::CheckIn);
});

it('closes check-in via the action', function () {
    $tournament = Tournament::factory()->checkIn()->create();

    $updated = app(CloseCheckin::class)->handle($tournament);

    expect($updated->status)->toBe(TournamentStatus::Live);
});

it('opens the check-in window via the scheduler tick when checkin_opens_at has passed', function () {
    $tournament = Tournament::factory()->enrollment()->create([
        'checkin_opens_at' => now()->subMinute(),
        'checkin_closes_at' => now()->addHour(),
    ]);

    $this->travelTo(now(), function () {
        $this->artisan('lanomat:tournament-tick')->assertExitCode(0);
    });

    expect($tournament->fresh()->status)->toBe(TournamentStatus::CheckIn);
});

it('does not open the check-in window early via the scheduler tick', function () {
    $tournament = Tournament::factory()->enrollment()->create([
        'checkin_opens_at' => now()->addHour(),
        'checkin_closes_at' => now()->addHours(2),
    ]);

    $this->artisan('lanomat:tournament-tick')->assertExitCode(0);

    expect($tournament->fresh()->status)->toBe(TournamentStatus::Enrollment);
});

it('closes the check-in window via the scheduler tick when checkin_closes_at has passed', function () {
    $tournament = Tournament::factory()->checkIn()->create([
        'checkin_opens_at' => now()->subHours(2),
        'checkin_closes_at' => now()->subMinute(),
    ]);

    $this->artisan('lanomat:tournament-tick')->assertExitCode(0);

    expect($tournament->fresh()->status)->toBe(TournamentStatus::Live);
});

it('is idempotent when the tick runs again after transitioning', function () {
    $tournament = Tournament::factory()->enrollment()->create([
        'checkin_opens_at' => now()->subMinute(),
        'checkin_closes_at' => now()->addHour(),
    ]);

    $this->artisan('lanomat:tournament-tick')->assertExitCode(0);
    expect($tournament->fresh()->status)->toBe(TournamentStatus::CheckIn);

    // Second run should not re-trigger open (already CheckIn) or close
    // (closes_at is still in the future).
    $this->artisan('lanomat:tournament-tick')->assertExitCode(0);
    expect($tournament->fresh()->status)->toBe(TournamentStatus::CheckIn);
});

it('resolves a German label for the check-in status', function () {
    app()->setLocale('de');

    expect(TournamentStatus::CheckIn->label())->toBe('Check-in');
});
