<?php

namespace App\Modules\Registration\Actions;

use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Events\RegistrationCancelled;
use App\Modules\Registration\Models\EventRegistration;

class CancelRegistration
{
    /**
     * Cancel a registration (idempotent no-op if already cancelled).
     *
     * Dispatches {@see RegistrationCancelled} on an actual state transition
     * so the Seating module can release any assigned seat (Task 6 listener,
     * see plan-mandated addition in the M2 seating/registration plan).
     */
    public function handle(EventRegistration $registration): EventRegistration
    {
        if ($registration->status === RegistrationStatus::Cancelled) {
            return $registration;
        }

        $registration->status = RegistrationStatus::Cancelled;
        // A cancelled registration is no longer "on site": drop the check-in
        // so arrival/presence counts stay honest (the seat is released by the
        // RegistrationCancelled listener).
        $registration->checked_in_at = null;
        $registration->save();

        RegistrationCancelled::dispatch($registration);

        return $registration;
    }
}
