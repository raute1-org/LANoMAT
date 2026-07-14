<?php

namespace App\Modules\Seating\Actions;

use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Exceptions\SeatException;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ClaimSeat
{
    public function handle(Seat $seat, EventRegistration $registration): SeatAssignment
    {
        if ($seat->event_id !== $registration->event_id) {
            throw SeatException::wrongEvent();
        }

        try {
            return DB::transaction(function () use ($seat, $registration): SeatAssignment {
                // Switching seats: drop the registration's current assignment first.
                SeatAssignment::query()
                    ->where('registration_id', $registration->id)
                    ->delete();

                return SeatAssignment::create([
                    'seat_id' => $seat->id,
                    'registration_id' => $registration->id,
                ]);
            });
        } catch (QueryException $e) {
            // seat_id UNIQUE violation -> two users raced for one seat.
            throw SeatException::taken();
        }
    }
}
