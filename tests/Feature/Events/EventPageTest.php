<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('shows the current event on the home page', function () {
    $event = Event::factory()->registration()->create(['name' => 'Testlan 2026']);

    $this->actingAs(User::factory()->create())->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Event/Show')
            ->where('event.name', 'Testlan 2026')
            ->where('event.status', 'registration')
            ->where('labels.when', 'Wann')
        );
});

it('shows the archive on the home page when no event is public', function () {
    Event::factory()->archived()->create();

    $this->actingAs(User::factory()->create())->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Event/Index'));
});

it('renders a single event by slug', function () {
    $event = Event::factory()->finished()->create(['name' => 'Old LAN']);

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Event/Show')
            ->where('event.name', 'Old LAN')
        );
});

it('lists past events in the archive descending', function () {
    Event::factory()->finished()->create(['name' => 'A', 'starts_at' => now()->subYear()]);
    Event::factory()->archived()->create(['name' => 'B', 'starts_at' => now()->subMonth()]);
    Event::factory()->draft()->create(['name' => 'Hidden']);

    $this->actingAs(User::factory()->create())->get('/events')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Event/Index')
            ->has('events', 2)
            ->where('events.0.name', 'B') // most recent first
        );
});

it('returns 404 for a draft event requested by slug', function () {
    $event = Event::factory()->draft()->create(['name' => 'Unannounced LAN']);

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}")->assertNotFound();
});

it('renders 404 for a draft event by slug (helper-driven)', function () {
    $event = Event::factory()->draft()->create();

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}")->assertNotFound();
});

it('exposes no registration for a user who has not registered', function () {
    $event = Event::factory()->registration()->create();

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('registration', null));
});

it('exposes the own active registration on the event page', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->checkedIn()->create();

    $this->actingAs($user)->get("/events/{$event->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('registration.status', 'confirmed')
            ->where('registration.checkedIn', true)
            ->missing('registration.qrSvg')
        );
});

it('ignores cancelled and foreign registrations on the event page', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->cancelled()->create();
    EventRegistration::factory()->for($event)->create();

    $this->actingAs($user)->get("/events/{$event->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('registration', null));
});

it('lists announced, registration and live events above the archive, soonest first, without drafts', function () {
    Event::factory()->announced()->create(['name' => 'Later', 'starts_at' => now()->addMonths(3)]);
    Event::factory()->registration()->create(['name' => 'Sooner', 'starts_at' => now()->addMonth()]);
    Event::factory()->live()->create(['name' => 'Running', 'starts_at' => now()->subDay()]);
    Event::factory()->draft()->create(['name' => 'Hidden']);
    Event::factory()->finished()->create(['name' => 'Done']);

    $this->actingAs(User::factory()->create())->get('/events')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Event/Index')
            ->has('current', 3)
            ->where('current.0.name', 'Running')
            ->where('current.1.name', 'Sooner')
            ->where('current.2.name', 'Later')
            ->where('current.1.availability', 'open')
            ->has('events', 1)
            ->where('events.0.name', 'Done'));
});
