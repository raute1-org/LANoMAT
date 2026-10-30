<?php

namespace App\Modules\Registration\Actions;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Exceptions\CheckInException;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Support\SelfCheckInWindow;

/**
 * A participant checks THEMSELVES in. Delegates to CheckInRegistration so the
 * side effects (checked_in_at, PresenceUpdated) are identical to the orga
 * scanner flow. Authorization (window, ownership) is the caller's job via
 * RegistrationPolicy::selfCheckIn.
 */
class SelfCheckIn
{
    public function __construct(private readonly CheckInRegistration $checkIn) {}

    public function handle(Event $event, EventRegistration $registration): EventRegistration
    {
        // Domain invariant, independent of the policy: the admin Gate::before
        // would otherwise let admins check in outside the window.
        if (! SelfCheckInWindow::isOpen($event) && $registration->checked_in_at === null) {
            throw CheckInException::windowClosed();
        }

        try {
            return $this->checkIn->handle($event, $registration->qr_token);
        } catch (CheckInException $e) {
            if ($registration->fresh()?->checked_in_at !== null) {
                // Second tap: idempotent success.
                return $registration->refresh();
            }

            throw $e;
        }
    }
}
