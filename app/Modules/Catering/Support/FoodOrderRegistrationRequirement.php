<?php

namespace App\Modules\Catering\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Support\MyRegistration;

/**
 * Product decision (F-R2B Q-2): ordering food and receiving the "Essen ist da"
 * message require a CONFIRMED event registration. Pending and cancelled do not
 * count, and there is no check-in requirement. The Registration module is only
 * reached through its public read-model ({@see MyRegistration}).
 */
class FoodOrderRegistrationRequirement
{
    public static function satisfiedBy(User $user, Event $event): bool
    {
        return MyRegistration::find($event, $user)?->status === RegistrationStatus::Confirmed;
    }
}
