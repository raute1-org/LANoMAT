<?php

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, Event $event): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, Event $event): bool
    {
        return $user->isOrga();
    }

    public function delete(User $user, Event $event): bool
    {
        return $user->isOrga();
    }

    /**
     * See where the participants of an event are (voice rooms, games) and follow them. Only the
     * participants of the event, that is people with a registration that is not cancelled, and
     * orga. A draft event is not for anybody (the caller answers that with a 404 first).
     */
    public function viewPeople(User $user, Event $event): bool
    {
        if (! $event->isPubliclyVisible()) {
            return false;
        }

        return $user->isOrga() || EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->where('status', '!=', RegistrationStatus::Cancelled->value)
            ->exists();
    }

    /**
     * The one-click "Check-in öffnet" trigger (see TriggerCheckinOpen) is
     * helper-or-above, unlike the rest of this policy — helpers run the live
     * event but don't configure it.
     */
    public function triggerCheckin(User $user, Event $event): bool
    {
        return $user->isHelper();
    }
}
