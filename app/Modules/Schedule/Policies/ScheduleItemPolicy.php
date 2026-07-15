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
}
