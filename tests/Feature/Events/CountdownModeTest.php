<?php

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('exposes hype props for an announced future event', function () {
    $event = Event::factory()->create(['status' => EventStatus::Announced, 'starts_at' => now()->addDays(14)]);
    EventRegistration::factory()->count(3)->create(['event_id' => $event->id]);

    $this->get("/events/{$event->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('event.hype.registrationCount', 3)
            ->where('event.hype.startsAt', $event->starts_at->toIso8601String()));
})->uses(RefreshDatabase::class);

it('excludes cancelled registrations from the hype registration count', function () {
    $event = Event::factory()->create(['status' => EventStatus::Registration, 'starts_at' => now()->addWeek()]);
    EventRegistration::factory()->count(2)->create(['event_id' => $event->id]);
    EventRegistration::factory()->cancelled()->create(['event_id' => $event->id]);

    $this->get("/events/{$event->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('event.hype.registrationCount', 2));
})->uses(RefreshDatabase::class);

it('omits hype for a finished event', function () {
    $event = Event::factory()->create(['status' => EventStatus::Finished, 'starts_at' => now()->subDay()]);

    $this->get("/events/{$event->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('event.hype', null));
})->uses(RefreshDatabase::class);

it('omits hype for an announced event whose start is in the past', function () {
    $event = Event::factory()->create(['status' => EventStatus::Announced, 'starts_at' => now()->subHour()]);

    $this->get("/events/{$event->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('event.hype', null));
})->uses(RefreshDatabase::class);

it('exposes arrival info on the event summary', function () {
    $event = Event::factory()->create([
        'status' => EventStatus::Announced,
        'arrival_info' => 'Parkplatz hinter der Halle, Einlass ab 16 Uhr.',
    ]);

    $this->get("/events/{$event->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('event.arrivalInfo', 'Parkplatz hinter der Halle, Einlass ab 16 Uhr.'));
})->uses(RefreshDatabase::class);
