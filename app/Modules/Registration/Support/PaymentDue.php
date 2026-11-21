<?php

namespace App\Modules\Registration\Support;

use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventPaymentSettings;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Models\TicketType;

/** Read model: what a registration owes and how to pay it. Owner-only data. */
class PaymentDue
{
    /**
     * @return array{amountCents: int, paid: bool, url: ?string, linkAppendsAmount: bool, reference: string, note: ?string, ticketName: string}|null
     */
    public static function forRegistration(EventRegistration $registration): ?array
    {
        $amount = $registration->ticket_price_cents;

        if ($amount === null || $amount <= 0 || $registration->status === RegistrationStatus::Cancelled) {
            return null;
        }

        $event = $registration->event()->firstOrFail();
        $user = $registration->user()->firstOrFail();
        $settings = EventPaymentSettings::forEvent($event);
        $ticket = TicketType::findForEvent($event, $registration->ticket_type);
        $link = $settings->payment_link;
        $ticketName = $ticket->name ?? TicketLabel::for($registration->ticket_type);

        return [
            'amountCents' => $amount,
            'paid' => $registration->paid_at !== null,
            'url' => PaymentLink::for($link, $amount),
            'linkAppendsAmount' => PaymentLink::appendsAmount($link),
            'reference' => PaymentReference::for(
                $event->name,
                $user->name,
                $ticketName,
            ),
            'ticketName' => $ticketName,
            'note' => $settings->payment_note !== '' ? $settings->payment_note : null,
        ];
    }
}
