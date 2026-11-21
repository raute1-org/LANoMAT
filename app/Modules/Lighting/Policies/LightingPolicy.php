<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Policies;

use App\Models\User;

/**
 * Registered as the `lighting.control` gate. Only the orga team (Admin|Orga)
 * steers the room lights. Scenes and rules use the same rule as model policies.
 */
class LightingPolicy
{
    public function control(User $user): bool
    {
        return $user->isOrga();
    }

    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, mixed $model = null): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, mixed $model = null): bool
    {
        return $user->isOrga();
    }

    public function delete(User $user, mixed $model = null): bool
    {
        return $user->isOrga();
    }
}
