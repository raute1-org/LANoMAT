<?php

namespace App\Modules\Seating\Actions;

use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Models\SeatAssignment;

class ReleaseSeat
{
    public function handle(EventRegistration $registration): void
    {
        SeatAssignment::query()
            ->where('registration_id', $registration->id)
            ->delete();
    }
}
