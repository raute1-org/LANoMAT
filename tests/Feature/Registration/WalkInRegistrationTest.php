<?php

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\CancelRegistration;
use App\Modules\Registration\Actions\RegisterForEvent;
use App\Modules\Registration\Enums\RegistrationAvailability;
use App\Modules\Registration\Exceptions\RegistrationException;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('maps every status to one availability', function (EventStatus $status, RegistrationAvailability $expected) {
    $event = Event::factory()->status($status)->create(['max_participants' => 5]);

    expect(RegistrationAvailability::for($event))->toBe($expected);
})->with([
    'draft' => [EventStatus::Draft, RegistrationAvailability::Closed],
    'announced' => [EventStatus::Announced, RegistrationAvailability::Upcoming],
    'registration' => [EventStatus::Registration, RegistrationAvailability::Open],
    'live' => [EventStatus::Live, RegistrationAvailability::Open],
    'finished' => [EventStatus::Finished, RegistrationAvailability::Closed],
    'archived' => [EventStatus::Archived, RegistrationAvailability::Closed],
]);

it('lets walk-ins register while the event is live and capacity remains', function () {
    $event = Event::factory()->live()->create(['max_participants' => 2]);

    $reg = app(RegisterForEvent::class)->handle($event, User::factory()->create(), 'standard');

    expect($reg->exists)->toBeTrue()
        ->and(RegistrationAvailability::freeSpots($event))->toBe(1);
});

it('treats a null participant limit as unlimited', function () {
    $event = Event::factory()->live()->create(['max_participants' => null]);

    expect(RegistrationAvailability::freeSpots($event))->toBeNull()
        ->and(RegistrationAvailability::for($event))->toBe(RegistrationAvailability::Open);
});

it('gives the last spot to exactly one of two walk-ins on a live event', function () {
    $event = Event::factory()->live()->create(['max_participants' => 1]);
    app(RegisterForEvent::class)->handle($event, User::factory()->create(), 'standard');

    expect(fn () => app(RegisterForEvent::class)->handle($event, User::factory()->create(), 'standard'))
        ->toThrow(RegistrationException::class, 'participant limit')
        ->and(RegistrationAvailability::for($event))->toBe(RegistrationAvailability::Full)
        ->and(EventRegistration::where('event_id', $event->id)->count())->toBe(1);
});

it('frees a spot again when a registration is cancelled', function () {
    $event = Event::factory()->live()->create(['max_participants' => 1]);
    $reg = app(RegisterForEvent::class)->handle($event, User::factory()->create(), 'standard');
    app(CancelRegistration::class)->handle($reg);

    expect(RegistrationAvailability::for($event))->toBe(RegistrationAvailability::Open);
});

it('rejects registration for statuses outside the window', function (EventStatus $status) {
    $event = Event::factory()->status($status)->create();

    expect(fn () => app(RegisterForEvent::class)->handle($event, User::factory()->create(), 'standard'))
        ->toThrow(RegistrationException::class, 'not open');
})->with([EventStatus::Draft, EventStatus::Announced, EventStatus::Finished, EventStatus::Archived]);

it('answers a full live event with the ausgebucht message on POST', function () {
    $event = Event::factory()->live()->create(['max_participants' => 1]);
    EventRegistration::factory()->for($event)->create();

    $this->actingAs(User::factory()->create())
        ->from("/events/{$event->slug}/register")
        ->post("/events/{$event->slug}/register", ['ticket_type' => 'standard'])
        ->assertRedirect("/events/{$event->slug}/register")
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => __('registration.errors.full')]);
});

it('exposes availability and free spots on the registration page', function () {
    $event = Event::factory()->live()->create(['max_participants' => 3]);
    EventRegistration::factory()->for($event)->create();

    $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/register")
        ->assertInertia(fn ($page) => $page
            ->where('availability', 'open')
            ->where('freeSpots', 2));
});

it('exposes availability on event page, dashboard, shared currentEvent and seating', function () {
    $event = Event::factory()->live()->create(['max_participants' => 1]);
    EventRegistration::factory()->for($event)->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get("/events/{$event->slug}")
        ->assertInertia(fn ($p) => $p->where('event.availability', 'full')->where('event.freeSpots', 0));
    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($p) => $p->where('event.availability', 'full'));
    $this->actingAs($user)->get("/events/{$event->slug}/seating")
        ->assertInertia(fn ($p) => $p->where('registrationState', 'full'))
        ->assertInertia(fn ($p) => $p->where('currentEvent.availability', 'full'));
});

it('reports seating registrationState open for live events with capacity', function () {
    $event = Event::factory()->live()->create();

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/seating")
        ->assertInertia(fn ($p) => $p->where('registrationState', 'open'));
});
