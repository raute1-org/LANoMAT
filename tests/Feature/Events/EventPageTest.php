<?php

use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('shows the current event on the home page', function () {
    $event = Event::factory()->registration()->create(['name' => 'Testlan 2026']);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Event/Show')
            ->where('event.name', 'Testlan 2026')
            ->where('event.status', 'registration')
        );
});

it('shows the archive on the home page when no event is public', function () {
    Event::factory()->archived()->create();

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Event/Index'));
});

it('renders a single event by slug', function () {
    $event = Event::factory()->finished()->create(['name' => 'Old LAN']);

    $this->get("/events/{$event->slug}")
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

    $this->get('/events')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Event/Index')
            ->has('events', 2)
            ->where('events.0.name', 'B') // most recent first
        );
});

it('returns 404 for a draft event requested by slug', function () {
    $event = Event::factory()->draft()->create(['name' => 'Unannounced LAN']);

    $this->get("/events/{$event->slug}")->assertNotFound();
});
