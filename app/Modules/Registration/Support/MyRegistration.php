<?php

namespace App\Modules\Registration\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Models\TicketType;

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
     * @return array{ticketType: string, ticketLabel: string, ticketPriceCents: int, ticketName: string, status: string, paid: bool, checkedIn: bool, arrival: array<string, mixed>}|null
     */
    public static function summary(Event $event, User $user): ?array
    {
        $registration = self::find($event, $user);

        if ($registration === null) {
            return null;
        }

        $type = TicketType::findForEvent($event, $registration->ticket_type);

        return [
            'ticketType' => $registration->ticket_type,
            'ticketLabel' => TicketLabel::for($registration->ticket_type),
            'ticketPriceCents' => $registration->ticket_price_cents ?? 0,
            'ticketName' => $type->name ?? TicketLabel::for($registration->ticket_type),
            'status' => $registration->status->value,
            'paid' => $registration->paid_at !== null,
            'checkedIn' => $registration->checked_in_at !== null,
            'arrival' => ArrivalPlanView::summary($registration, $user),
        ];
    }
}
