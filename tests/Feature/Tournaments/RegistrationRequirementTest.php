<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
});

it('denies enroll without a registration for the tournament event', function () {
    $tournament = Tournament::factory()->enrollment()->create();
    $user = User::factory()->create();

    expect($user->can('enroll', $tournament))->toBeFalse();

    EventRegistration::factory()->create(['event_id' => $tournament->event_id, 'user_id' => $user->id]);

    expect($user->can('enroll', $tournament))->toBeTrue();
});

it('denies enroll for a registration on another event or a cancelled one', function () {
    $tournament = Tournament::factory()->enrollment()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->create(['user_id' => $user->id]);
    EventRegistration::factory()->cancelled()->create(['event_id' => $tournament->event_id, 'user_id' => $user->id]);

    expect($user->can('enroll', $tournament))->toBeFalse();
});

it('lets orga bypass the registration requirement', function () {
    $tournament = Tournament::factory()->enrollment()->create();

    expect(User::factory()->orga()->create()->can('enroll', $tournament))->toBeTrue();
});

it('denies check-in without a registration and allows it with one', function () {
    $tournament = Tournament::factory()->checkIn()->create();
    $user = User::factory()->create();
    $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

    expect($user->can('checkIn', $entry))->toBeFalse();

    EventRegistration::factory()->create(['event_id' => $tournament->event_id, 'user_id' => $user->id]);

    expect($user->can('checkIn', $entry))->toBeTrue();
});

it('flashes a friendly error instead of enrolling an unregistered user over HTTP', function () {
    $tournament = Tournament::factory()->enrollment()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('tournaments.enroll', $tournament))->assertRedirect();

    expect(TournamentEntry::query()->where('tournament_id', $tournament->id)->count())->toBe(0);
});

it('exposes the missing-registration state on the tournaments index', function () {
    $event = Event::factory()->live()->create();
    Tournament::factory()->for($event)->enrollment()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get("/events/{$event->slug}/tournaments")
        ->assertInertia(fn ($page) => $page
            ->where('registration.required', true)
            ->where('registration.open', true)
            ->where('registration.url', route('events.register', $event->slug)));

    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);

    $this->actingAs($user)->get("/events/{$event->slug}/tournaments")
        ->assertInertia(fn ($page) => $page->where('registration.required', false));
});
