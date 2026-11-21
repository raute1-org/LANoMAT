<?php

declare(strict_types=1);

namespace App\Modules\Identity\Policies;

use App\Models\User;

/**
 * Only the role assignment is policy-gated here; browsing users in the admin
 * panel stays governed by the panel access check. Admins pass via `Gate::before`,
 * everyone else is denied explicitly.
 */
class UserPolicy
{
    public function changeRole(User $actor, User $target): bool
    {
        return $actor->isAdmin();
    }
}
