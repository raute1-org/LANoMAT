<?php

use App\Modules\Events\Models\Event;
use App\Modules\Seating\Actions\GenerateSeatGrid;
use App\Modules\Seating\Models\Seat;

it('generates a rows x cols grid of seats', function () {
    $event = Event::factory()->create();

    $created = app(GenerateSeatGrid::class)->handle($event, 2, 3, 'A');

    expect($created)->toBe(6)
        ->and(Seat::where('event_id', $event->id)->count())->toBe(6)
        ->and(Seat::where('event_id', $event->id)->where('label', 'A1-1')->exists())->toBeTrue();
});

it('is idempotent and skips existing labels', function () {
    $event = Event::factory()->create();
    app(GenerateSeatGrid::class)->handle($event, 2, 2, 'A');

    $created = app(GenerateSeatGrid::class)->handle($event, 2, 2, 'A');

    expect($created)->toBe(0)
        ->and(Seat::where('event_id', $event->id)->count())->toBe(4);
});
