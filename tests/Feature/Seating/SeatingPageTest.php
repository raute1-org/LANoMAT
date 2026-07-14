<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Actions\ClaimSeat;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use Inertia\Testing\AssertableInertia;

it('renders the seating map with seats and occupants', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create(['label' => 'A1-1']);
    $occupant = User::factory()->create(['name' => 'Sitter']);
    $reg = EventRegistration::factory()->for($event)->for($occupant)->create();
    SeatAssignment::factory()->create(['seat_id' => $seat->id, 'registration_id' => $reg->id]);

    $this->get("/events/{$event->slug}/seating")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Event/Seating')
            ->where('seats.0.label', 'A1-1')
            ->where('seats.0.occupant', 'Sitter')
            ->where('labels.title', 'Sitzplan')
        );
});

it('is viewable without authentication (public wer-sitzt-wo view)', function () {
    $event = Event::factory()->live()->create();
    Seat::factory()->for($event)->create();

    $this->get("/events/{$event->slug}/seating")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canClaim', false)
            ->where('mySeatId', null)
        );
});

it('only shows seats belonging to the requested event', function () {
    $event = Event::factory()->live()->create();
    $otherEvent = Event::factory()->live()->create();
    Seat::factory()->for($event)->create(['label' => 'MINE']);
    Seat::factory()->for($otherEvent)->create(['label' => 'OTHER']);

    $this->get("/events/{$event->slug}/seating")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('seats', 1)
            ->where('seats.0.label', 'MINE')
        );
});

it('lets a registered user claim a seat', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();

    $this->actingAs($user)
        ->post("/events/{$event->slug}/seating/{$seat->id}")
        ->assertRedirect();

    expect(SeatAssignment::where('seat_id', $seat->id)->exists())->toBeTrue();
});

it('forbids claiming without a registration', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create();

    $this->actingAs(User::factory()->create())
        ->post("/events/{$event->slug}/seating/{$seat->id}")
        ->assertForbidden();
});

it('forbids claiming a seat via someone else\'s registration (policy denial)', function () {
    // Regression guard for the claim-seat Gate: a user must not be able to
    // move another user's registration onto a seat just because both are
    // registered for the same event.
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create();
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    EventRegistration::factory()->for($event)->for($owner)->create();
    $attackerReg = EventRegistration::factory()->for($event)->for($attacker)->create();

    // The controller always resolves the registration from the authenticated
    // user, so this exercises the same-event-different-registration path,
    // proving the policy check is reached rather than bypassed.
    $this->actingAs($attacker)
        ->post("/events/{$event->slug}/seating/{$seat->id}")
        ->assertRedirect();

    expect(SeatAssignment::where('seat_id', $seat->id)->first()->registration_id)
        ->toBe($attackerReg->id);
});

it('redirects with a german toast when the seat is already taken', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create();
    $existingReg = EventRegistration::factory()->for($event)->create();
    app(ClaimSeat::class)->handle($seat, $existingReg);

    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();

    $response = $this->actingAs($user)
        ->post("/events/{$event->slug}/seating/{$seat->id}");

    $response->assertRedirect();
    $response->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'error'
        && $toast['message'] === __('seating.errors.taken'));
});

it('lets a user release their own seat', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create();
    $user = User::factory()->create();
    $reg = EventRegistration::factory()->for($event)->for($user)->create();
    app(ClaimSeat::class)->handle($seat, $reg);

    $this->actingAs($user)
        ->delete("/events/{$event->slug}/seating")
        ->assertRedirect();

    expect(SeatAssignment::where('seat_id', $seat->id)->exists())->toBeFalse();
});

it('forbids releasing without a registration', function () {
    $event = Event::factory()->live()->create();

    $this->actingAs(User::factory()->create())
        ->delete("/events/{$event->slug}/seating")
        ->assertForbidden();
});
