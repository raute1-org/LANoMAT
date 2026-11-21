<?php

namespace App\Modules\Registration\Support;

use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Models\TicketType;

/** Server-side rules for removing or deactivating a ticket type. */
class TicketTypeGuard
{
    public static function isUsed(TicketType $ticketType): bool
    {
        return EventRegistration::query()
            ->where('event_id', $ticketType->event_id)
            ->where('ticket_type', $ticketType->key)
            ->exists();
    }

    /** True when this is the event's only active ticket type. */
    public static function isLastActive(TicketType $ticketType): bool
    {
        if (! $ticketType->active) {
            return false;
        }

        return ! TicketType::query()
            ->where('event_id', $ticketType->event_id)
            ->where('active', true)
            ->whereKeyNot($ticketType->id)
            ->exists();
    }

    public static function canDelete(TicketType $ticketType): bool
    {
        return ! self::isUsed($ticketType) && ! self::isLastActive($ticketType);
    }

    public static function canDeactivate(TicketType $ticketType): bool
    {
        return ! self::isLastActive($ticketType);
    }
}
