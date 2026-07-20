<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Policies;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;

/**
 * Not a per-model CRUD policy (the jukebox has no owning model with a
 * one-to-one authorization story) — registered as named gates in
 * AppServiceProvider (`jukebox.participate`/`jukebox.moderate`), mirroring
 * how `claim-seat` wraps SeatAssignmentPolicy::claim.
 */
class JukeboxPolicy
{
    /**
     * Only checked-in participants may queue tracks or cast votes.
     */
    public function participate(User $user, Event $event): bool
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->whereNotNull('checked_in_at')
            ->exists();
    }

    /**
     * Orga/helper override: skip/remove anytime regardless of votes.
     */
    public function moderate(User $user, Event $event): bool
    {
        return $user->isHelper();
    }
}
