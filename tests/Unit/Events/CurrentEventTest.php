<?php

use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\CurrentEvent;

it('returns null when no event is in a public status', function () {
    Event::factory()->draft()->create();
    Event::factory()->finished()->create();
    Event::factory()->archived()->create();

    expect(app(CurrentEvent::class)->get())->toBeNull();
});

it('prefers a live event over registration and announced', function () {
    $announced = Event::factory()->announced()->create(['starts_at' => now()->addDays(30)]);
    $registration = Event::factory()->registration()->create(['starts_at' => now()->addDays(20)]);
    $live = Event::factory()->live()->create(['starts_at' => now()->subDay()]);

    expect(app(CurrentEvent::class)->get()->is($live))->toBeTrue();
});

it('prefers registration over announced when no live event exists', function () {
    Event::factory()->announced()->create();
    $registration = Event::factory()->registration()->create();

    expect(app(CurrentEvent::class)->get()->is($registration))->toBeTrue();
});

it('breaks ties within a status by latest starts_at', function () {
    $earlier = Event::factory()->registration()->create(['starts_at' => now()->addDays(10)]);
    $later = Event::factory()->registration()->create(['starts_at' => now()->addDays(40)]);

    expect(app(CurrentEvent::class)->get()->is($later))->toBeTrue();
});
