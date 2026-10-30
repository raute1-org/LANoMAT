<?php

namespace App\Modules\Registration\Policies;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
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

    /**
     * Ruling C (OQ-N6): a participant can cancel their own registration only
     * until they are checked in and while the event is not finished/archived;
     * orga can always cancel (via the admin panel).
     */
    public function cancel(User $user, EventRegistration $registration): bool
    {
        if ($user->isOrga()) {
            return true;
        }

        return $registration->user_id === $user->id && self::cancelBlockedReason($registration) === null;
    }

    /**
     * Why a participant cannot cancel right now (`checked_in` | `event_over`),
     * or null when they can. Single source for the policy and the UI prop.
     */
    public static function cancelBlockedReason(EventRegistration $registration): ?string
    {
        if ($registration->checked_in_at !== null) {
            return 'checked_in';
        }

        $event = $registration->event()->first();

        if ($event !== null && in_array($event->status, [EventStatus::Finished, EventStatus::Archived], true)) {
            return 'event_over';
        }

        return null;
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

    /**
     * Own, non-cancelled registration, until the event is finished/archived.
     */
    public function updateArrivalPlan(User $user, EventRegistration $registration): bool
    {
        $event = $registration->event()->first();

        return $event !== null
            && $registration->user_id === $user->id
            && $registration->status !== RegistrationStatus::Cancelled
            && ! in_array($event->status, [EventStatus::Finished, EventStatus::Archived], true);
    }
}
