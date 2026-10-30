<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Tournaments\Actions\AnnounceTournament;
use App\Modules\Tournaments\Actions\EnrollSolo;
use App\Modules\Tournaments\Actions\OpenTournamentEnrollment;
use App\Modules\Tournaments\Actions\ReopenToDraft;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\EditTournament;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
});

function readyTournament(array $overrides = []): Tournament
{
    return Tournament::factory()->singleElim()->create([
        'event_id' => Event::factory()->live()->create()->id,
        'game_id' => Game::factory()->create()->id,
        'max_entries' => 8,
        'rules' => "Best of 3\nNo cheating",
        'starts_at' => now()->addDays(2),
        'checkin_opens_at' => now()->addDay(),
        'checkin_closes_at' => now()->addDay()->addHour(),
        ...$overrides,
    ]);
}

it('announces a complete draft', function () {
    expect(app(AnnounceTournament::class)->handle(readyTournament())->status)->toBe(TournamentStatus::Announced);
});

it('refuses to announce an incomplete draft and lists what is missing', function () {
    $tournament = readyTournament(['game_id' => null]);

    try {
        app(AnnounceTournament::class)->handle($tournament);
        $this->fail('expected exception');
    } catch (TournamentException $e) {
        expect(__($e->translationKey, $e->translationParams))->toContain('Spiel');
    }

    expect($tournament->fresh()->status)->toBe(TournamentStatus::Draft);
});

it('only announces from draft', function () {
    $tournament = Tournament::factory()->enrollment()->create();

    expect(fn () => app(AnnounceTournament::class)->handle($tournament))->toThrow(TournamentException::class);
});

it('opens enrollment from announced and, as a shortcut, from draft; never from enrollment', function () {
    $announced = readyTournament(['status' => TournamentStatus::Announced]);
    expect(app(OpenTournamentEnrollment::class)->handle($announced)->status)->toBe(TournamentStatus::Enrollment);

    expect(app(OpenTournamentEnrollment::class)->handle(readyTournament())->status)->toBe(TournamentStatus::Enrollment);

    expect(fn () => app(OpenTournamentEnrollment::class)->handle($announced->fresh()))->toThrow(TournamentException::class);
});

it('returns an announced tournament to draft', function () {
    $tournament = Tournament::factory()->announced()->create();

    expect(app(ReopenToDraft::class)->handle($tournament)->status)->toBe(TournamentStatus::Draft);
});

it('refuses sign-up while announced', function () {
    $tournament = Tournament::factory()->announced()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $tournament->event_id, 'user_id' => $user->id]);

    expect(Gate::forUser($user)->allows('enroll', $tournament))->toBeFalse();
    expect(fn () => app(EnrollSolo::class)->handle($tournament, $user))->toThrow(TournamentException::class);
});

it('hides draft tournaments from participants but shows announced ones', function () {
    $event = Event::factory()->live()->create();
    Tournament::factory()->for($event)->create(['name' => 'Secret Cup']);
    Tournament::factory()->for($event)->announced()->create(['name' => 'Soon Cup']);

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/tournaments")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tournaments', 1)
            ->where('tournaments.0.name', 'Soon Cup')
            ->where('tournaments.0.status', 'announced'));

    $this->actingAs(User::factory()->orga()->create())->get("/events/{$event->slug}/tournaments")
        ->assertInertia(fn (AssertableInertia $page) => $page->has('tournaments', 2));
});

it('404s the show page of a draft for participants and renders announced info', function () {
    $draft = readyTournament();
    $announced = readyTournament(['status' => TournamentStatus::Announced]);

    $this->actingAs(User::factory()->create())->get("/tournaments/{$draft->id}")->assertNotFound();
    $this->actingAs(User::factory()->orga()->create())->get("/tournaments/{$draft->id}")->assertOk();

    $this->actingAs(User::factory()->create())->get("/tournaments/{$announced->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tournament.status', 'announced')
            ->where('tournament.rules', "Best of 3\nNo cheating")
            ->where('tournament.maxEntries', 8)
            ->where('labels.announced_hint', 'Anmeldung öffnet bald'));
});

it('404s the overlays for a draft tournament', function () {
    $draft = readyTournament();

    $this->get("/overlay/tournament/{$draft->id}/bracket")->assertNotFound();
});

it('keeps drafts off the schedule and withdraws the item when returning to draft', function () {
    $tournament = readyTournament();
    expect(ScheduleItem::query()->where('ref_type', 'tournament')->where('ref_id', $tournament->id)->exists())->toBeFalse();

    app(AnnounceTournament::class)->handle($tournament);
    expect(ScheduleItem::query()->where('ref_type', 'tournament')->where('ref_id', $tournament->id)->count())->toBe(1);

    app(ReopenToDraft::class)->handle($tournament->fresh());
    expect(ScheduleItem::query()->where('ref_type', 'tournament')->where('ref_id', $tournament->id)->exists())->toBeFalse();
});

it('offers the announce and back-to-draft lifecycle actions in the admin', function () {
    $this->actingAs(User::factory()->orga()->create());

    $draft = readyTournament();
    Livewire::test(EditTournament::class, ['record' => $draft->getRouteKey()])
        ->assertActionVisible('announce')
        ->assertActionVisible('openEnrollment')
        ->assertActionHidden('closeEnrollment')
        ->callAction('announce');
    expect($draft->fresh()->status)->toBe(TournamentStatus::Announced);

    Livewire::test(EditTournament::class, ['record' => $draft->getRouteKey()])
        ->assertActionHidden('announce')
        ->assertActionVisible('openEnrollment')
        ->assertActionVisible('closeEnrollment')
        ->assertActionHidden('start');

    TournamentEntry::factory()->create(['tournament_id' => $draft->id]);
    Livewire::test(EditTournament::class, ['record' => $draft->getRouteKey()])
        ->callAction('openEnrollment');
    expect($draft->fresh()->status)->toBe(TournamentStatus::Enrollment);
});
