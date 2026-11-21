<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventPaymentSettings;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('requires auth to view the registration page', function () {
    $event = Event::factory()->registration()->create();

    // Standard Laravel `auth` middleware redirect target (no custom redirectTo
    // configured in bootstrap/app.php).
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
            ->where('tickets.0.key', 'standard')
            ->where('tickets.0.priceCents', 0)
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
            ->where('registration.ticketLabel', 'Standard')
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

it('returns 404 for a draft event on the registration page', function () {
    $event = Event::factory()->draft()->create();

    $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/register")
        ->assertNotFound();
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

function paidEvent(?string $link = 'https://paypal.me/example-orga'): Event
{
    $event = Event::factory()->registration()->create();
    TicketType::where('event_id', $event->id)->where('key', 'standard')->update(['price_cents' => 2500, 'name' => 'Standard', 'sort' => 1]);
    TicketType::create(['event_id' => $event->id, 'key' => 'vip', 'name' => 'VIP', 'description' => 'Mit Snacks', 'price_cents' => 4000, 'sort' => 2, 'active' => true]);
    TicketType::create(['event_id' => $event->id, 'key' => 'old', 'name' => 'Old', 'price_cents' => 100, 'sort' => 3, 'active' => false]);
    $settings = EventPaymentSettings::forEvent($event);
    $settings->payment_link = $link;
    $settings->save();

    return $event;
}

it('lists active ticket types with prices in sort order and hides inactive ones', function () {
    $event = paidEvent();

    $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/register")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tickets', 2)
            ->where('tickets.0.key', 'standard')
            ->where('tickets.0.priceCents', 2500)
            ->where('tickets.1.key', 'vip')
            ->where('tickets.1.description', 'Mit Snacks')
            ->where('tickets.1.priceCents', 4000)
            ->missing('registration.payment')
        );
});

it('gives the owner a payment link ending with the amount', function () {
    $event = paidEvent();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create(['ticket_type' => 'standard']);
    EventRegistration::query()->where('user_id', $user->id)->update(['ticket_price_cents' => 2500]);

    $this->actingAs($user)
        ->get("/events/{$event->slug}/register")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('registration.payment.amountCents', 2500)
            ->where('registration.payment.paid', false)
            ->where('registration.payment.url', fn ($url) => str_ends_with((string) $url, '/25EUR'))
        );
});

it('marks the payment paid and has no url without a link', function () {
    $event = paidEvent(null);
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create(['ticket_type' => 'standard']);
    EventRegistration::query()->where('user_id', $user->id)->update(['ticket_price_cents' => 2500, 'paid_at' => now()]);

    $this->actingAs($user)
        ->get("/events/{$event->slug}/register")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('registration.payment.paid', true)
            ->where('registration.payment.url', null)
        );
});

it('never exposes another users payment', function () {
    $event = paidEvent();
    $owner = User::factory()->create();
    EventRegistration::factory()->for($event)->for($owner)->create(['ticket_type' => 'standard']);
    EventRegistration::query()->where('user_id', $owner->id)->update(['ticket_price_cents' => 2500]);

    $this->actingAs(User::factory()->create())
        ->get("/events/{$event->slug}/register")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('registration', null)
            ->missing('registration.payment')
        );
});
