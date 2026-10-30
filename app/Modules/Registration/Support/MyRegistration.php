<?php

namespace App\Modules\Registration\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;

/**
 * Read model: the signed-in user's own active (non-cancelled) registration for
 * an event, as a small wire DTO. Never includes the QR payload — that stays on
 * the dedicated registration page.
 */
class MyRegistration
{
    public static function find(Event $event, User $user): ?EventRegistration
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->where('status', '!=', RegistrationStatus::Cancelled->value)
            ->first();
    }

    /**
     * @return array{ticketType: string, ticketLabel: string, status: string, paid: bool, checkedIn: bool}|null
     */
    public static function summary(Event $event, User $user): ?array
    {
        $registration = self::find($event, $user);

        return $registration === null ? null : [
            'ticketType' => $registration->ticket_type,
            'ticketLabel' => TicketLabel::for($registration->ticket_type),
            'status' => $registration->status->value,
            'paid' => $registration->paid_at !== null,
            'checkedIn' => $registration->checked_in_at !== null,
        ];
    }
}
