<?php

namespace App\Modules\Seating\Actions;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Exceptions\SeatException;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ClaimSeat
{
    public static function eventIsOver(Event $event): bool
    {
        return in_array($event->status, [EventStatus::Finished, EventStatus::Archived], true);
    }

    public function handle(Seat $seat, EventRegistration $registration): SeatAssignment
    {
        if ($seat->event_id !== $registration->event_id) {
            throw SeatException::wrongEvent();
        }

        if ($registration->status !== RegistrationStatus::Confirmed) {
            throw SeatException::registrationNotConfirmed();
        }

        if (self::eventIsOver($seat->event()->firstOrFail())) {
            throw SeatException::eventOver();
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
            // Only a unique-key violation on seat_id means two users raced
            // for the same seat. Any other failure is a real error and must
            // not be misreported as "seat taken".
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            throw SeatException::taken();
        }
    }
}
