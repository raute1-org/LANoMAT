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

it('rolls back the whole batch atomically when a seat fails partway through', function () {
    $event = Event::factory()->create();
    $failOnThirdSeat = 0;

    Seat::creating(function () use (&$failOnThirdSeat) {
        $failOnThirdSeat++;
        if ($failOnThirdSeat === 3) {
            throw new RuntimeException('simulated failure partway through the batch');
        }
    });

    try {
        expect(fn () => app(GenerateSeatGrid::class)->handle($event, 2, 3, 'A'))
            ->toThrow(RuntimeException::class);
    } finally {
        Seat::flushEventListeners();
    }

    // Atomic: the two seats created before the failure must not survive.
    expect(Seat::where('event_id', $event->id)->count())->toBe(0);
});
