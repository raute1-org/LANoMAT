<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamMember;
use App\Modules\Tournaments\Actions\EnrollSolo;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Actions\WithdrawEntry;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
});

it('lets a solo participant withdraw their own entry during enrollment and check-in only', function () {
    $user = User::factory()->create();

    foreach ([TournamentStatus::Enrollment, TournamentStatus::CheckIn] as $status) {
        $tournament = Tournament::factory()->create(['status' => $status]);
        $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

        expect($user->can('withdraw', $entry))->toBeTrue();
    }

    foreach ([TournamentStatus::Draft, TournamentStatus::Live, TournamentStatus::Finished] as $status) {
        $tournament = Tournament::factory()->create(['status' => $status]);
        $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

        expect($user->can('withdraw', $entry))->toBeFalse();
    }
});

it('denies withdrawing someone else\'s entry and already withdrawn entries', function () {
    $tournament = Tournament::factory()->enrollment()->create();
    $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id]);

    expect(User::factory()->create()->can('withdraw', $entry))->toBeFalse();

    $own = User::factory()->create();
    $withdrawn = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $own->id, 'status' => EntryStatus::Withdrawn]);

    expect($own->can('withdraw', $withdrawn))->toBeFalse();
});

it('lets only the team owner withdraw a team entry, not a plain member', function () {
    $tournament = Tournament::factory()->enrollment()->create();
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $member = User::factory()->create();
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $member->id]);
    $entry = TournamentEntry::factory()->team()->create(['tournament_id' => $tournament->id, 'team_id' => $team->id]);

    expect($owner->can('withdraw', $entry))->toBeTrue()
        ->and($member->can('withdraw', $entry))->toBeFalse();
});

it('withdraws via HTTP, frees the slot and lets another user enroll', function () {
    $tournament = Tournament::factory()->enrollment()->create(['max_entries' => 1]);
    $user = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $tournament->event_id, 'user_id' => $user->id]);
    app(EnrollSolo::class)->handle($tournament, $user);

    $this->actingAs($user)->post(route('tournaments.withdraw', $tournament))->assertRedirect();

    expect(TournamentEntry::query()->where('user_id', $user->id)->first()->status)->toBe(EntryStatus::Withdrawn);

    expect(app(EnrollSolo::class)->handle($tournament, User::factory()->create())->status)->toBe(EntryStatus::Registered);
});

it('403s an HTTP withdraw once the tournament is live and keeps the entry', function () {
    $tournament = Tournament::factory()->live()->create();
    $user = User::factory()->create();
    $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

    $this->actingAs($user)->post(route('tournaments.withdraw', $tournament))->assertForbidden();

    expect($entry->fresh()->status)->toBe(EntryStatus::Registered);
});

it('404s an HTTP withdraw when the user has no entry', function () {
    $tournament = Tournament::factory()->enrollment()->create();

    $this->actingAs(User::factory()->create())->post(route('tournaments.withdraw', $tournament))->assertNotFound();
});

it('loses the race against a start: a stale entry cannot withdraw after the tournament went live', function () {
    $tournament = Tournament::factory()->checkIn()->singleElim()->create();
    $entries = TournamentEntry::factory()->count(2)->checkedIn()->create(['tournament_id' => $tournament->id]);
    $stale = $entries->first();

    // The caller authorized against a stale (check-in) snapshot, then the start won.
    app(StartTournament::class)->handle($tournament);

    expect(fn () => app(WithdrawEntry::class)->handle($stale))->toThrow(TournamentException::class);
    expect($stale->fresh()->status)->toBe(EntryStatus::CheckedIn);
});

it('is idempotent on an already withdrawn entry', function () {
    $tournament = Tournament::factory()->enrollment()->create();
    $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'status' => EntryStatus::Withdrawn]);

    expect(app(WithdrawEntry::class)->handle($entry)->status)->toBe(EntryStatus::Withdrawn);
});

it('clears the check-in timestamp when a checked-in entry withdraws', function () {
    $tournament = Tournament::factory()->checkIn()->create();
    $entry = TournamentEntry::factory()->checkedIn()->create(['tournament_id' => $tournament->id]);

    $result = app(WithdrawEntry::class)->handle($entry);

    expect($result->status)->toBe(EntryStatus::Withdrawn)->and($result->checked_in_at)->toBeNull();
});

it('exposes my entry and its withdraw ability on the index and show pages', function () {
    $tournament = Tournament::factory()->for(Event::factory()->live())->enrollment()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $tournament->event_id, 'user_id' => $user->id]);
    $entry = app(EnrollSolo::class)->handle($tournament, $user);
    $slug = $tournament->event->slug;

    $this->actingAs($user)->get("/events/{$slug}/tournaments")
        ->assertInertia(fn ($page) => $page
            ->where('tournaments.0.myEntry.id', $entry->id)
            ->where('tournaments.0.myEntry.canWithdraw', true));

    $this->actingAs($user)->get("/tournaments/{$tournament->id}")
        ->assertInertia(fn ($page) => $page->where('myEntry.canWithdraw', true));

    $this->actingAs(User::factory()->create())->get("/events/{$slug}/tournaments")
        ->assertInertia(fn ($page) => $page->where('tournaments.0.myEntry', null));
});

it('lets a withdrawn user re-enroll and resolves the new entry, not the withdrawn one', function () {
    $tournament = Tournament::factory()->for(Event::factory()->live())->enrollment()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $tournament->event_id, 'user_id' => $user->id]);
    $first = app(EnrollSolo::class)->handle($tournament, $user);
    app(WithdrawEntry::class)->handle($first);
    $second = app(EnrollSolo::class)->handle($tournament, $user);

    $this->actingAs($user)->get("/tournaments/{$tournament->id}")
        ->assertInertia(fn ($page) => $page->where('myEntryId', $second->id));
});
