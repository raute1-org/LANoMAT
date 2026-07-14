<?php

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\CancelRegistration;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Actions\ClaimSeat;
use App\Modules\Seating\Actions\ReleaseSeat;
use App\Modules\Seating\Exceptions\SeatException;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('claims a free seat for a registration', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create();
    $reg = EventRegistration::factory()->for($event)->create();

    $assignment = app(ClaimSeat::class)->handle($seat, $reg);

    expect($assignment->seat_id)->toBe($seat->id)
        ->and($assignment->registration_id)->toBe($reg->id);
});

it('rejects claiming a seat from another event', function () {
    $reg = EventRegistration::factory()->for(Event::factory()->live())->create();
    $seat = Seat::factory()->for(Event::factory()->live())->create();

    expect(fn () => app(ClaimSeat::class)->handle($seat, $reg))
        ->toThrow(SeatException::class);
});

it('lets a user switch seats (releases the old one)', function () {
    $event = Event::factory()->live()->create();
    $reg = EventRegistration::factory()->for($event)->create();
    $seatA = Seat::factory()->for($event)->create();
    $seatB = Seat::factory()->for($event)->create();

    app(ClaimSeat::class)->handle($seatA, $reg);
    app(ClaimSeat::class)->handle($seatB, $reg);

    expect(SeatAssignment::where('registration_id', $reg->id)->count())->toBe(1)
        ->and(SeatAssignment::where('seat_id', $seatB->id)->exists())->toBeTrue()
        ->and(SeatAssignment::where('seat_id', $seatA->id)->exists())->toBeFalse();
});

it('rejects a second registration claiming the same seat (db unique race)', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create();
    $regA = EventRegistration::factory()->for($event)->create();
    $regB = EventRegistration::factory()->for($event)->create();

    app(ClaimSeat::class)->handle($seat, $regA);

    expect(fn () => app(ClaimSeat::class)->handle($seat, $regB))
        ->toThrow(SeatException::class);

    expect(SeatAssignment::where('seat_id', $seat->id)->first()->registration_id)->toBe($regA->id);
});

it('rethrows a QueryException that is not a unique-key violation', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create();
    $reg = EventRegistration::factory()->for($event)->create();

    // Build a real QueryException carrying an arbitrary non-23505 SQLSTATE
    // (PDOException::$code is not final), so we can assert the action does
    // not misreport a real failure as "seat taken".
    $pdoException = new PDOException('simulated connection error');
    $pdoException->errorInfo = ['55000', 1, 'simulated connection error'];
    (new ReflectionProperty($pdoException, 'code'))->setValue($pdoException, '55000');

    $queryException = new QueryException(
        'pgsql',
        'insert into "seat_assignments" ("seat_id", "registration_id") values (?, ?)',
        [],
        $pdoException,
    );

    DB::shouldReceive('transaction')->once()->andThrow($queryException);

    expect(fn () => app(ClaimSeat::class)->handle($seat, $reg))
        ->toThrow(QueryException::class);
});

it('releases a seat', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create();
    $reg = EventRegistration::factory()->for($event)->create();
    app(ClaimSeat::class)->handle($seat, $reg);

    app(ReleaseSeat::class)->handle($reg);

    expect(SeatAssignment::where('registration_id', $reg->id)->exists())->toBeFalse();
});

it('releases the seat when a registration with a seat is cancelled', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create();
    $reg = EventRegistration::factory()->for($event)->create();
    app(ClaimSeat::class)->handle($seat, $reg);

    app(CancelRegistration::class)->handle($reg);

    expect(SeatAssignment::where('registration_id', $reg->id)->exists())->toBeFalse();
});

it('does not error when a registration without a seat is cancelled', function () {
    $event = Event::factory()->live()->create();
    $reg = EventRegistration::factory()->for($event)->create();

    app(CancelRegistration::class)->handle($reg);

    expect($reg->fresh()->status->value)->toBe('cancelled');
});
