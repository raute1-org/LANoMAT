<?php

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use Carbon\CarbonImmutable;

it('generates a slug from the name', function () {
    $event = Event::factory()->create(['name' => 'Winter LAN 2027']);

    expect($event->slug)->toBe('winter-lan-2027');
});

it('appends a counter suffix when the slug already exists', function () {
    $a = Event::factory()->create(['name' => 'Winter LAN 2027']);
    $b = Event::factory()->create(['name' => 'Winter LAN 2027']);
    $c = Event::factory()->create(['name' => 'Winter LAN 2027']);

    expect($a->slug)->toBe('winter-lan-2027')
        ->and($b->slug)->toBe('winter-lan-2027-2')
        ->and($c->slug)->toBe('winter-lan-2027-3');
});

it('keeps the slug unchanged when the name is updated later', function () {
    $event = Event::factory()->create(['name' => 'Winter LAN 2027']);

    $event->update(['name' => 'Renamed LAN']);

    expect($event->fresh())
        ->slug->toBe('winter-lan-2027')
        ->name->toBe('Renamed LAN');
});

it('casts status to the EventStatus enum and settings to an array', function () {
    $event = Event::factory()->create([
        'status' => EventStatus::Registration,
        'settings' => ['tickets' => ['standard']],
    ]);

    expect($event->fresh()->status)->toBe(EventStatus::Registration)
        ->and($event->fresh()->settings)->toBe(['tickets' => ['standard']]);
});

it('casts starts_at and ends_at to Carbon instances', function () {
    $event = Event::factory()->create();

    // The app configures Date::use(CarbonImmutable::class) globally
    // (see AppServiceProvider::configureDefaults()), so datetime casts
    // resolve to CarbonImmutable rather than Illuminate\Support\Carbon.
    expect($event->starts_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($event->ends_at)->toBeInstanceOf(CarbonImmutable::class);
});
