<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Policies;

use App\Models\User;
use App\Modules\Provisioning\Models\Hypervisor;

/**
 * Hypervisor credentials are orga-only infrastructure (admins pass through
 * Gate::before). Helpers get nothing here, like the remote-host registry.
 */
class HypervisorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, Hypervisor $hypervisor): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, Hypervisor $hypervisor): bool
    {
        return $user->isOrga();
    }

    public function delete(User $user, Hypervisor $hypervisor): bool
    {
        return $user->isOrga();
    }
}
