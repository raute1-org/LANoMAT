<?php

namespace App\Modules\Infoscreen\Actions;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Notifications\CheckinOpened;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * The "Check-in öffnet" one-click trigger: notifies every confirmed
 * registrant of the given event (bell is the source of truth, Discord DM
 * mirrors per the `checkin` preference — see {@see CheckinOpened}).
 */
class TriggerCheckinOpen
{
    public function handle(Event $event, User $actor): void
    {
        Gate::forUser($actor)->authorize('triggerCheckin', $event);

        $registrants = User::query()
            ->whereIn('id', $event->registrations()
                ->where('status', RegistrationStatus::Confirmed)
                ->pluck('user_id'))
            ->get();

        if ($registrants->isEmpty()) {
            return;
        }

        Notification::send($registrants, new CheckinOpened($event));
    }
}
