<?php

namespace App\Modules\Registration\Policies;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;

class RegistrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function create(User $user, Event $event): bool
    {
        return $event->status === EventStatus::Registration;
    }

    public function cancel(User $user, EventRegistration $registration): bool
    {
        return $user->isOrga() || $registration->user_id === $user->id;
    }

    public function update(User $user, EventRegistration $registration): bool
    {
        return $user->isOrga();
    }
}
