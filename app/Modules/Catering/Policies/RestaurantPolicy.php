<?php

namespace App\Modules\Catering\Policies;

use App\Models\User;
use App\Modules\Catering\Models\Restaurant;

/**
 * Restaurants are admin data (contact, notes, menu editing); participants only ever see the
 * name, description and logo through the round, never through the restaurant itself.
 */
class RestaurantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, Restaurant $restaurant): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, Restaurant $restaurant): bool
    {
        return $user->isOrga();
    }

    /** Only while no round points at the restaurant (the foreign key restricts it as well). */
    public function delete(User $user, Restaurant $restaurant): bool
    {
        return $user->isOrga() && ! $restaurant->foodOrders()->exists();
    }
}
