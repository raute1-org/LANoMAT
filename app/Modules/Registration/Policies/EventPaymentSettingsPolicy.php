<?php

namespace App\Modules\Registration\Policies;

use App\Models\User;
use App\Modules\Registration\Models\EventPaymentSettings;

class EventPaymentSettingsPolicy
{
    public function view(User $user, EventPaymentSettings $settings): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, EventPaymentSettings $settings): bool
    {
        return $user->isOrga();
    }
}
