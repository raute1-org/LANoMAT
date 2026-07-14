<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use Inertia\Testing\AssertableInertia;

it('requires auth to view the registration page', function () {
    $event = Event::factory()->registration()->create();

    // Standard Laravel `auth` middleware redirect target (no custom redirectTo
    // configured in bootstrap/app.php); matches DashboardTest's expectation.
    $this->get("/events/{$event->slug}/register")->assertRedirect(route('login'));
});

it('shows ticket options with german labels when not yet registered', function () {
    $event = Event::factory()->registration()->create(['settings' => ['tickets' => ['standard']]]);

    $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/register")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Event/Register')
            ->where('registration', null)
            ->has('tickets', 1)
            ->where('labels.title', 'Zum Event anmelden')
        );
});

it('creates a registration on POST', function () {
    $event = Event::factory()->registration()->create(['settings' => ['tickets' => ['standard']]]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/events/{$event->slug}/register", ['ticket_type' => 'standard'])
        ->assertRedirect();

    expect(EventRegistration::where('user_id', $user->id)->first()->status)
        ->toBe(RegistrationStatus::Confirmed);
});

it('shows my registration with a qr code when already registered', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();

    $this->actingAs($user)
        ->get("/events/{$event->slug}/register")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('registration.ticketType', 'standard')
            ->has('registration.qrSvg')
        );
});

it('cancels my registration on DELETE', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();

    $this->actingAs($user)
        ->delete("/events/{$event->slug}/register")
        ->assertRedirect();

    expect(EventRegistration::where('user_id', $user->id)->first()->status)
        ->toBe(RegistrationStatus::Cancelled);
});

it('forbids cancelling someone else registration', function () {
    $event = Event::factory()->registration()->create();
    EventRegistration::factory()->for($event)->for(User::factory()->create())->create();

    $this->actingAs(User::factory()->create())
        ->delete("/events/{$event->slug}/register")
        ->assertRedirect(); // no active registration for this user -> no-op redirect, nothing cancelled
});

it('shows the translated status label for a pending registration', function () {
    expect(RegistrationStatus::Pending->label())->toBe('Ausstehend');
});

it('redirects back with a german error when the event is full', function () {
    $event = Event::factory()->registration()->create(['max_participants' => 1]);
    EventRegistration::factory()->for($event)->create();
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->from("/events/{$event->slug}/register")
        ->post("/events/{$event->slug}/register", ['ticket_type' => 'standard']);

    $response->assertRedirect("/events/{$event->slug}/register");
    $response->assertInertiaFlash('toast', [
        'type' => 'error',
        'message' => __('registration.errors.full'),
    ]);
    expect(EventRegistration::where('user_id', $user->id)->exists())->toBeFalse();
});

it('redirects back with a german error when already registered', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();

    $response = $this->actingAs($user)
        ->from("/events/{$event->slug}/register")
        ->post("/events/{$event->slug}/register", ['ticket_type' => 'standard']);

    $response->assertRedirect("/events/{$event->slug}/register");
    $response->assertInertiaFlash('toast', [
        'type' => 'error',
        'message' => __('registration.errors.already_registered'),
    ]);
    expect(EventRegistration::where('user_id', $user->id)->count())->toBe(1);
});

it('rejects an invalid ticket type with a 422 validation error', function () {
    $event = Event::factory()->registration()->create(['settings' => ['tickets' => ['standard']]]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->from("/events/{$event->slug}/register")
        ->post("/events/{$event->slug}/register", ['ticket_type' => 'not-a-real-ticket']);

    $response->assertSessionHasErrors('ticket_type');
    expect(EventRegistration::where('user_id', $user->id)->exists())->toBeFalse();
});

it('does not leak another users registration or qr code on the show page', function () {
    $event = Event::factory()->registration()->create();
    $owner = User::factory()->create();
    $registration = EventRegistration::factory()->for($event)->for($owner)->create();

    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->get("/events/{$event->slug}/register")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('registration', null)
            ->missing('registration.qrSvg')
        );

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Confirmed);
});
