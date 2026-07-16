<?php

namespace App\Modules\Infoscreen\Policies;

use App\Models\User;
use App\Modules\Infoscreen\Models\TombolaPrize;

/**
 * Tombola prizes are orga-maintained content (Filament CRUD only); the draw
 * itself is a separate, helper-reachable action gated by
 * {@see InfoscreenScenePolicy::drawTombola}.
 */
class TombolaPrizePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, TombolaPrize $prize): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, TombolaPrize $prize): bool
    {
        return $user->isOrga();
    }

    public function delete(User $user, TombolaPrize $prize): bool
    {
        return $user->isOrga();
    }

    public function reorder(User $user): bool
    {
        return $user->isOrga();
    }
}
