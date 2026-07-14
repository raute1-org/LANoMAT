<?php

namespace App\Modules\Seating\Policies;

use App\Models\User;
use App\Modules\Seating\Models\Seat;

class SeatPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, Seat $seat): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, Seat $seat): bool
    {
        return $user->isOrga();
    }

    public function delete(User $user, Seat $seat): bool
    {
        return $user->isOrga();
    }
}
