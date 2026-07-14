<?php

namespace App\Modules\Seating\Policies;

use App\Models\User;
use App\Modules\Registration\Models\EventRegistration;

class SeatAssignmentPolicy
{
    public function claim(User $user, EventRegistration $registration): bool
    {
        return $user->isOrga() || $registration->user_id === $user->id;
    }
}
