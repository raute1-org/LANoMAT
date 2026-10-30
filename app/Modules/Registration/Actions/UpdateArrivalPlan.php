<?php

namespace App\Modules\Registration\Actions;

use App\Modules\Registration\Exceptions\RegistrationException;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Support\EventDays;
use App\Modules\Registration\ValueObjects\ArrivalPlan;

/**
 * Stores the participant's arrival/departure plan. Authorization is the
 * caller's job (RegistrationPolicy::updateArrivalPlan); this action guards the
 * data (event days only, arrival not after departure).
 */
class UpdateArrivalPlan
{
    public function handle(EventRegistration $registration, ArrivalPlan $plan): EventRegistration
    {
        $event = $registration->event()->firstOrFail();

        if (! $plan->isValidFor(EventDays::dates($event))) {
            throw RegistrationException::invalidArrivalPlan();
        }

        self::apply($registration, $plan);
        $registration->save();

        return $registration;
    }

    public static function apply(EventRegistration $registration, ArrivalPlan $plan): void
    {
        $registration->setAttribute('arrival_day', $plan->arrivalDay);
        $registration->setAttribute('arrival_window', $plan->arrivalWindow);
        $registration->setAttribute('departure_day', $plan->departureDay);
        $registration->setAttribute('departure_window', $plan->departureWindow);
    }
}
