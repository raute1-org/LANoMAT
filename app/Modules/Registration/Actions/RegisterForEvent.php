<?php

namespace App\Modules\Registration\Actions;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Exceptions\RegistrationException;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Support\Facades\DB;

class RegisterForEvent
{
    public function handle(Event $event, User $user, string $ticketType): EventRegistration
    {
        if ($event->status !== EventStatus::Registration) {
            throw RegistrationException::eventNotOpen();
        }

        if (! in_array($ticketType, $this->allowedTickets($event), true)) {
            throw RegistrationException::invalidTicketType($ticketType);
        }

        return DB::transaction(function () use ($event, $user, $ticketType): EventRegistration {
            // PostgreSQL rejects `FOR UPDATE` combined with an aggregate
            // (e.g. COUNT), so the locked rows are fetched once and both
            // checks are derived from that result set in PHP rather than
            // issuing a second, separately-aggregated query.
            $active = EventRegistration::query()
                ->where('event_id', $event->id)
                ->where('status', '!=', RegistrationStatus::Cancelled->value)
                ->lockForUpdate()
                ->get(['id', 'user_id']);

            if ($active->contains('user_id', $user->id)) {
                throw RegistrationException::alreadyRegistered();
            }

            if ($event->max_participants !== null && $active->count() >= $event->max_participants) {
                throw RegistrationException::full();
            }

            $registration = new EventRegistration([
                'event_id' => $event->id,
                'user_id' => $user->id,
                'ticket_type' => $ticketType,
            ]);
            $registration->status = RegistrationStatus::Confirmed;
            $registration->save();

            return $registration;
        });
    }

    /**
     * @return array<int, string>
     */
    private function allowedTickets(Event $event): array
    {
        $tickets = $event->settings['tickets'] ?? [];

        return empty($tickets) ? ['standard'] : array_values($tickets);
    }
}
