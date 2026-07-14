<?php

use App\Models\User;
use App\Modules\Tournaments\Actions\CheckInEntry;
use App\Modules\Tournaments\Actions\EnrollSolo;
use App\Modules\Tournaments\Actions\OpenCheckin;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The scheduler-tick autostart tests dispatch a real TournamentStarted,
    // which Task 21's voice-provisioning listener reacts to — fake Mumble
    // globally so this suite never hits a real server.
    fakeMumble();
});

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

it('does not autostart a tournament via the scheduler tick merely because checkin_closes_at has passed', function () {
    $tournament = Tournament::factory()->checkIn()->create([
        'checkin_opens_at' => now()->subHours(2),
        'checkin_closes_at' => now()->subMinute(),
        // starts_at defaults to now()->addDay() - not yet reached, so the
        // tick's autostart guard must not fire from checkin_closes_at alone.
    ]);

    $this->artisan('lanomat:tournament-tick')->assertExitCode(0);

    // Check-in closing is time-gated inside CheckInEntry itself, not a
    // status transition. The CheckIn -> Live transition is owned
    // exclusively by StartTournament, which is gated on starts_at, not
    // checkin_closes_at - so the tournament stays in CheckIn even though its
    // check-in window has closed.
    expect($tournament->fresh()->status)->toBe(TournamentStatus::CheckIn);
});

it('autostarts a tournament via the scheduler tick once starts_at has arrived', function () {
    $tournament = Tournament::factory()->checkIn()->singleElim()->create([
        'starts_at' => now()->subMinute(),
    ]);
    TournamentEntry::factory()->checkedIn()->count(4)->create(['tournament_id' => $tournament->id]);

    $this->artisan('lanomat:tournament-tick')->assertExitCode(0);

    expect($tournament->fresh()->status)->toBe(TournamentStatus::Live)
        ->and(GameMatch::where('tournament_id', $tournament->id)->count())->toBe(3);
});

it('does not autostart a tournament via the scheduler tick before starts_at has arrived', function () {
    $tournament = Tournament::factory()->checkIn()->singleElim()->create([
        'starts_at' => now()->addMinute(),
    ]);
    TournamentEntry::factory()->checkedIn()->count(4)->create(['tournament_id' => $tournament->id]);

    $this->artisan('lanomat:tournament-tick')->assertExitCode(0);

    expect($tournament->fresh()->status)->toBe(TournamentStatus::CheckIn);
});

it('is idempotent when the tick runs again after opening check-in', function () {
    $tournament = Tournament::factory()->enrollment()->create([
        'checkin_opens_at' => now()->subMinute(),
        'checkin_closes_at' => now()->addHour(),
    ]);

    $this->artisan('lanomat:tournament-tick')->assertExitCode(0);
    expect($tournament->fresh()->status)->toBe(TournamentStatus::CheckIn);

    // Second run should not re-trigger open (already CheckIn, no longer
    // matches the Enrollment source-state guard).
    $this->artisan('lanomat:tournament-tick')->assertExitCode(0);
    expect($tournament->fresh()->status)->toBe(TournamentStatus::CheckIn);
});

it('resolves a German label for the check-in status', function () {
    app()->setLocale('de');

    expect(TournamentStatus::CheckIn->label())->toBe('Check-in');
});
