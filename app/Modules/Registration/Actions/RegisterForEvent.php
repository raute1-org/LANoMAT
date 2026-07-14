<?php

namespace App\Modules\Registration\Actions;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Exceptions\RegistrationException;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            // Lock the PARENT event row first. `events_registrations` has no
            // rows at all for a brand-new event, so a `FOR UPDATE` on the
            // (empty) child rowset locks nothing and two concurrent first
            // registrations on e.g. max_participants=1 can both pass the
            // capacity check (phantom read). Locking the parent Event row
            // instead serializes ALL registrations for this event — every
            // concurrent caller queues up on this lock before reading
            // settings/capacity or the registrations table, so the capacity
            // count below is always read after any concurrent writer has
            // committed or rolled back. That makes a `FOR UPDATE` on the
            // child rows redundant; a plain read is safe once the parent
            // is locked.
            $event = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            $existing = EventRegistration::query()
                ->where('event_id', $event->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing !== null && $existing->status !== RegistrationStatus::Cancelled) {
                throw RegistrationException::alreadyRegistered();
            }

            $activeCount = EventRegistration::query()
                ->where('event_id', $event->id)
                ->where('status', '!=', RegistrationStatus::Cancelled->value)
                ->count();

            if ($event->max_participants !== null && $activeCount >= $event->max_participants) {
                throw RegistrationException::full();
            }

            if ($existing !== null) {
                // Reactivate the cancelled registration in place rather than
                // inserting a new row, since (event_id, user_id) is uniquely
                // constrained regardless of status. The `creating` hook that
                // normally assigns qr_token only fires on insert, so the
                // token is regenerated explicitly here — the old token may
                // have been shared/displayed while the registration was
                // cancelled and must not remain valid.
                $existing->ticket_type = $ticketType;
                $existing->status = RegistrationStatus::Confirmed;
                $existing->qr_token = Str::random(40);
                $existing->save();

                return $existing;
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
