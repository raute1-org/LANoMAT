<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\Tournaments\Actions\CheckInEntry;
use App\Modules\Tournaments\Actions\OpenCheckin;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\CreateTournament;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\EditTournament;
use App\Modules\Tournaments\Jobs\StartTournamentJob;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
});

// F-S4-6 / OQ-S4-4
it('refuses to start any format with fewer than two participating entries in German', function (TournamentFormat $format, int $checkedIn) {
    $tournament = Tournament::factory()->checkIn()->create(['format' => $format]);
    TournamentEntry::factory()->checkedIn()->count($checkedIn)->create(['tournament_id' => $tournament->id]);
    // registered-but-not-checked-in entries do not count when check-in is required
    TournamentEntry::factory()->count(3)->create(['tournament_id' => $tournament->id]);

    try {
        app(StartTournament::class)->handle($tournament);
        $this->fail('expected a TournamentException');
    } catch (TournamentException $e) {
        expect(__($e->translationKey, $e->translationParams))->toContain('mindestens 2');
    }

    expect($tournament->fresh()->status)->toBe(TournamentStatus::CheckIn);
})->with([
    'SE none' => [TournamentFormat::SingleElimination, 0],
    'SE one' => [TournamentFormat::SingleElimination, 1],
    'RR none' => [TournamentFormat::RoundRobin, 0],
    'RR one' => [TournamentFormat::RoundRobin, 1],
    'DE one' => [TournamentFormat::DoubleElimination, 1],
]);

it('shows the start refusal as a Filament danger notification instead of a 500', function () {
    $tournament = Tournament::factory()->checkIn()->singleElim()->create();
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
        ->callAction('start')
        ->assertNotified();

    expect($tournament->fresh()->status)->toBe(TournamentStatus::CheckIn);
});

// OQ-S4-6
it('rejects a single-elimination field above 64', function () {
    $tournament = Tournament::factory()->checkIn()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(65)->create(['tournament_id' => $tournament->id]);

    expect(fn () => app(StartTournament::class)->handle($tournament))->toThrow(TournamentException::class);
    expect($tournament->fresh()->status)->toBe(TournamentStatus::CheckIn);
});

it('does not dispatch an autostart job for an under-filled tournament and logs once', function () {
    Queue::fake();
    Log::spy();
    Cache::flush();
    $tournament = Tournament::factory()->checkIn()->singleElim()->create(['starts_at' => now()->subMinute()]);
    TournamentEntry::factory()->checkedIn()->create(['tournament_id' => $tournament->id]);

    $this->artisan('lanomat:tournament-tick')->assertExitCode(0);
    $this->artisan('lanomat:tournament-tick')->assertExitCode(0);
    $this->artisan('lanomat:tournament-tick')->assertExitCode(0);

    Queue::assertNotPushed(StartTournamentJob::class);
    Log::shouldHaveReceived('warning')->once();
});

it('dispatches the autostart job again once enough entries are checked in', function () {
    Queue::fake();
    Cache::flush();
    $tournament = Tournament::factory()->checkIn()->singleElim()->create(['starts_at' => now()->subMinute()]);
    TournamentEntry::factory()->checkedIn()->count(2)->create(['tournament_id' => $tournament->id]);

    $this->artisan('lanomat:tournament-tick')->assertExitCode(0);

    Queue::assertPushed(StartTournamentJob::class, 1);
});

it('does not fail the queued start job when the tournament cannot start', function () {
    Log::spy();
    $tournament = Tournament::factory()->checkIn()->singleElim()->create(['starts_at' => now()->subMinute()]);
    TournamentEntry::factory()->checkedIn()->create(['tournament_id' => $tournament->id]);

    (new StartTournamentJob($tournament))->handle(app(StartTournament::class));

    expect($tournament->fresh()->status)->toBe(TournamentStatus::CheckIn);
    Log::shouldHaveReceived('warning')->once();
});

// F-S4-7 / OQ-S4-3
it('pulls a future check-in opening to now when check-in is opened manually', function () {
    $tournament = Tournament::factory()->enrollment()->create([
        'checkin_opens_at' => now()->addHours(3),
        'checkin_closes_at' => now()->addHours(4),
    ]);

    $updated = app(OpenCheckin::class)->handle($tournament);

    expect($updated->status)->toBe(TournamentStatus::CheckIn)
        ->and($updated->checkin_opens_at->lte(now()))->toBeTrue();

    // participants can really check in now
    $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id]);
    expect(app(CheckInEntry::class)->handle($entry)->checked_in_at)->not->toBeNull();
});

it('keeps a past check-in opening untouched on manual open', function () {
    $opens = now()->subHour()->startOfSecond();
    $tournament = Tournament::factory()->enrollment()->create([
        'checkin_opens_at' => $opens,
        'checkin_closes_at' => now()->addHour(),
    ]);

    $updated = app(OpenCheckin::class)->handle($tournament);

    expect($updated->checkin_opens_at->equalTo($opens))->toBeTrue();
});

it('refuses a manual early open when the check-in window has already closed', function () {
    $tournament = Tournament::factory()->enrollment()->create([
        'checkin_opens_at' => now()->addHours(3),
        'checkin_closes_at' => now()->subMinute(),
    ]);

    try {
        app(OpenCheckin::class)->handle($tournament);
        $this->fail('expected a TournamentException');
    } catch (TournamentException $e) {
        expect(__($e->translationKey))->toContain('Check-in');
    }

    expect($tournament->fresh()->status)->toBe(TournamentStatus::Enrollment);
});

// F-S4-13: form validation
it('rejects a check-in close that is not after the check-in open in the tournament form', function () {
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(CreateTournament::class)
        ->fillForm([
            'event_id' => Event::factory()->live()->create()->id,
            'game_id' => Game::factory()->create()->id,
            'name' => 'Window Cup',
            'format' => 'single_elimination',
            'team_size' => 1,
            'max_entries' => 8,
            'starts_at' => now()->addDays(2),
            'checkin_opens_at' => now()->addDay()->addHour(),
            'checkin_closes_at' => now()->addDay(),
        ])
        ->call('create')
        ->assertHasFormErrors(['checkin_closes_at']);
});
