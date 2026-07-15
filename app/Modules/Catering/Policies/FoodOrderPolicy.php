<?php

namespace App\Modules\Catering\Policies;

use App\Models\User;
use App\Modules\Catering\Models\FoodOrder;

class FoodOrderPolicy
{
    /**
     * Food orders are public — anyone (including guests, handled by the
     * calling controller) may view the list/menu, mirroring
     * ScheduleItemPolicy.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, FoodOrder $order): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, FoodOrder $order): bool
    {
        return $user->isOrga();
    }

    public function open(User $user, FoodOrder $order): bool
    {
        return $user->isOrga();
    }

    public function close(User $user, FoodOrder $order): bool
    {
        return $user->isOrga();
    }
}
