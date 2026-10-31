<?php

namespace App\Modules\Schedule\Policies;

use App\Models\User;
use App\Modules\Schedule\Models\ScheduleItem;

class ScheduleItemPolicy
{
    /**
     * The schedule is public — anyone (including guests, handled by the
     * calling controller) may view it.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, ScheduleItem $item): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, ScheduleItem $item): bool
    {
        return $user->isOrga();
    }

    public function delete(User $user, ScheduleItem $item): bool
    {
        return $user->isOrga();
    }

    /**
     * Any authenticated user may favorite an item of a publicly visible
     * event — favorites are a purely personal "remember this for me" marker.
     * Items of draft events stay invisible (even their existence), like the
     * schedule page and ICS export.
     */
    public function favorite(User $user, ScheduleItem $item): bool
    {
        return $item->event()->firstOrFail()->isPubliclyVisible();
    }

    /**
     * Unfavoriting only ever acts on the caller's own favorite (the
     * controller resolves the acting user server-side, never from a
     * client-supplied ID), so this is likewise open to any authenticated
     * user.
     */
    public function unfavorite(User $user, ScheduleItem $item): bool
    {
        return true;
    }
}
