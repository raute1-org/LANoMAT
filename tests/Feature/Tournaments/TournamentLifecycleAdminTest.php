<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Tournaments\Actions\OpenTournamentEnrollment;
use App\Modules\Tournaments\Actions\ReopenToDraft;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\CreateTournament;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\EditTournament;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\ListTournaments;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
});

function readyDraft(array $overrides = []): Tournament
{
    return Tournament::factory()->singleElim()->create([
        'game_id' => Game::factory()->create()->id,
        'max_entries' => 8,
        'starts_at' => now()->addDays(2),
        'checkin_opens_at' => now()->addDay(),
        'checkin_closes_at' => now()->addDay()->addHour(),
        ...$overrides,
    ]);
}

it('opens enrollment on a complete draft', function () {
    $tournament = app(OpenTournamentEnrollment::class)->handle(readyDraft());

    expect($tournament->status)->toBe(TournamentStatus::Enrollment);
});

it('refuses to open enrollment when required fields are missing and lists them in German', function () {
    $tournament = readyDraft(['game_id' => null, 'max_entries' => null, 'checkin_opens_at' => null]);

    try {
        app(OpenTournamentEnrollment::class)->handle($tournament);
        $this->fail('expected exception');
    } catch (TournamentException $e) {
        $message = __($e->translationKey, $e->translationParams);
        expect($message)->toContain('Spiel')->toContain('Max. Anmeldungen')->toContain('Check-in öffnet');
    }

    expect($tournament->fresh()->status)->toBe(TournamentStatus::Draft);
});

it('does not require a check-in window when check-in is switched off', function () {
    $tournament = readyDraft(['checkin_opens_at' => null, 'checkin_closes_at' => null, 'settings' => ['require_checkin' => false]]);

    expect(app(OpenTournamentEnrollment::class)->handle($tournament)->status)->toBe(TournamentStatus::Enrollment);
});

it('only opens enrollment from draft', function () {
    $tournament = Tournament::factory()->enrollment()->create();

    expect(fn () => app(OpenTournamentEnrollment::class)->handle($tournament))->toThrow(TournamentException::class);
});

it('reopens to draft only without active entries', function () {
    $empty = Tournament::factory()->enrollment()->create();
    expect(app(ReopenToDraft::class)->handle($empty)->status)->toBe(TournamentStatus::Draft);

    $busy = Tournament::factory()->enrollment()->create();
    TournamentEntry::factory()->create(['tournament_id' => $busy->id]);
    expect(fn () => app(ReopenToDraft::class)->handle($busy))->toThrow(TournamentException::class);
    expect($busy->fresh()->status)->toBe(TournamentStatus::Enrollment);

    $withdrawnOnly = Tournament::factory()->enrollment()->create();
    TournamentEntry::factory()->create(['tournament_id' => $withdrawnOnly->id, 'status' => EntryStatus::Withdrawn]);
    expect(app(ReopenToDraft::class)->handle($withdrawnOnly)->status)->toBe(TournamentStatus::Draft);
});

it('refuses to reopen a live tournament to draft', function () {
    $tournament = Tournament::factory()->live()->create();

    expect(fn () => app(ReopenToDraft::class)->handle($tournament))->toThrow(TournamentException::class);
});

it('shows only the lifecycle actions valid for the current status', function () {
    $this->actingAs(User::factory()->orga()->create());

    $draft = Tournament::factory()->create();
    Livewire::test(EditTournament::class, ['record' => $draft->getRouteKey()])
        ->assertActionVisible('openEnrollment')
        ->assertActionHidden('closeEnrollment')
        ->assertActionHidden('openCheckin')
        ->assertActionHidden('start');

    $enrollment = Tournament::factory()->enrollment()->create();
    Livewire::test(EditTournament::class, ['record' => $enrollment->getRouteKey()])
        ->assertActionHidden('openEnrollment')
        ->assertActionVisible('closeEnrollment')
        ->assertActionVisible('openCheckin')
        ->assertActionVisible('start');

    TournamentEntry::factory()->create(['tournament_id' => $enrollment->id]);
    Livewire::test(EditTournament::class, ['record' => $enrollment->getRouteKey()])
        ->assertActionHidden('closeEnrollment');
});

it('opens enrollment via the header action and reports missing fields as a notification', function () {
    $this->actingAs(User::factory()->orga()->create());

    $incomplete = Tournament::factory()->create();
    Livewire::test(EditTournament::class, ['record' => $incomplete->getRouteKey()])
        ->callAction('openEnrollment')
        ->assertNotified();
    expect($incomplete->fresh()->status)->toBe(TournamentStatus::Draft);

    $complete = readyDraft();
    Livewire::test(EditTournament::class, ['record' => $complete->getRouteKey()])
        ->callAction('openEnrollment')
        ->assertNotified();
    expect($complete->fresh()->status)->toBe(TournamentStatus::Enrollment);
});

it('offers the same lifecycle actions as table row actions', function () {
    $this->actingAs(User::factory()->orga()->create());
    $tournament = readyDraft();

    Livewire::test(ListTournaments::class)
        ->callAction(TestAction::make('openEnrollment')->table($tournament));

    expect($tournament->fresh()->status)->toBe(TournamentStatus::Enrollment);
});

it('moves enrollment to check-in via the header action', function () {
    $this->actingAs(User::factory()->orga()->create());
    $tournament = Tournament::factory()->enrollment()->create();

    Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])
        ->callAction('openCheckin');

    expect($tournament->fresh()->status)->toBe(TournamentStatus::CheckIn);
});

it('forbids participants from calling the lifecycle actions', function () {
    $this->actingAs(User::factory()->create());

    // The panel and the resource (policy `manage`) are orga-only.
    $this->get('/admin/tournaments')->assertForbidden();
    expect(Gate::allows('manage', readyDraft()))->toBeFalse();
});

it('walks the full lifecycle: admin creates a draft, opens enrollment, participant enrolls, orga starts', function () {
    $orga = User::factory()->orga()->create();
    $event = Event::factory()->live()->create();
    $game = Game::factory()->create();

    $this->actingAs($orga);

    Livewire::test(CreateTournament::class)
        ->fillForm([
            'event_id' => $event->id,
            'game_id' => $game->id,
            'name' => 'Lifecycle Cup',
            'format' => 'single_elimination',
            'team_size' => 1,
            'max_entries' => 8,
            'starts_at' => now()->addDays(2),
            'checkin_opens_at' => now()->addDay(),
            'checkin_closes_at' => now()->addDay()->addHour(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tournament = Tournament::query()->where('name', 'Lifecycle Cup')->firstOrFail();
    expect($tournament->status)->toBe(TournamentStatus::Draft);

    // Draft: invisible to participants, so enrolling answers 404 (not 403).
    $player = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $player->id]);
    $this->actingAs($player)->post("/tournaments/{$tournament->id}/enroll")->assertNotFound();

    $this->actingAs($orga);
    Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])->callAction('openEnrollment');
    expect($tournament->fresh()->status)->toBe(TournamentStatus::Enrollment);

    $this->actingAs($player)->post("/tournaments/{$tournament->id}/enroll")->assertRedirect();
    $entry = TournamentEntry::query()->where('tournament_id', $tournament->id)->where('user_id', $player->id)->firstOrFail();

    // Second entry so a bracket exists; both checked in through the Filament-free path.
    $other = TournamentEntry::factory()->checkedIn()->create(['tournament_id' => $tournament->id]);
    $entry->status = EntryStatus::CheckedIn;
    $entry->save();

    $this->actingAs($orga);
    Livewire::test(EditTournament::class, ['record' => $tournament->getRouteKey()])->callAction('start');

    expect($tournament->fresh()->status)->toBe(TournamentStatus::Live)
        ->and($other->fresh())->not->toBeNull();
});
