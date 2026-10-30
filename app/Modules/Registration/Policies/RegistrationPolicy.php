<?php

namespace App\Modules\Registration\Policies;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationAvailability;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Support\SelfCheckInWindow;

class RegistrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function create(User $user, Event $event): bool
    {
        return RegistrationAvailability::acceptsStatus($event->status);
    }

    public function cancel(User $user, EventRegistration $registration): bool
    {
        return $user->isOrga() || $registration->user_id === $user->id;
    }

    public function update(User $user, EventRegistration $registration): bool
    {
        return $user->isOrga();
    }

    /**
     * Self check-in via the on-site QR: own, non-cancelled registration while
     * the check-in window is open. Payment status is irrelevant.
     */
    public function selfCheckIn(User $user, EventRegistration $registration): bool
    {
        $event = $registration->event()->first();

        return $event !== null
            && $registration->user_id === $user->id
            && $registration->status !== RegistrationStatus::Cancelled
            && SelfCheckInWindow::isOpen($event);
    }
}
