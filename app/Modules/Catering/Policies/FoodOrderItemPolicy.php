<?php

namespace App\Modules\Catering\Policies;

use App\Models\User;
use App\Modules\Catering\Models\FoodOrderItem;

class FoodOrderItemPolicy
{
    /**
     * Any authenticated user may attempt to place an item — the actual
     * open/close/window enforcement happens in PlaceFoodOrderItem, not
     * here, since it depends on the parent FoodOrder's live state under
     * lock rather than a simple ability check.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, FoodOrderItem $item): bool
    {
        return $user->isOrga() || $item->user_id === $user->id;
    }
}
