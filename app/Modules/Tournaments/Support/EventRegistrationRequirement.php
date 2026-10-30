<?php

namespace App\Modules\Tournaments\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationAvailability;
use App\Modules\Registration\Support\MyRegistration;
use App\Modules\Tournaments\Models\Tournament;

/**
 * Ruling A (OQ-07): enrolling in / checking in to a tournament requires a
 * non-cancelled event registration of the acting user. Orga bypasses. The
 * Registration module is only reached through its public read-model
 * ({@see MyRegistration}) and {@see RegistrationAvailability}.
 */
class EventRegistrationRequirement
{
    public static function satisfiedBy(User $user, Tournament $tournament): bool
    {
        if ($user->isOrga()) {
            return true;
        }

        $event = $tournament->event()->first();

        return $event !== null && MyRegistration::find($event, $user) !== null;
    }

    /**
     * Wire shape for the tournament pages: whether the viewer still has to
     * register for the event first, whether registration can be done now, and
     * where.
     *
     * @return array{required: bool, open: bool, url: string}
     */
    public static function viewState(?User $user, Event $event): array
    {
        $required = $user !== null && ! $user->isOrga() && MyRegistration::find($event, $user) === null;

        return [
            'required' => $required,
            'open' => RegistrationAvailability::for($event) === RegistrationAvailability::Open,
            'url' => route('events.register', $event->slug),
        ];
    }
}
