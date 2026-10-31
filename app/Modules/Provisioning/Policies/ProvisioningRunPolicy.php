<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Policies;

use App\Models\User;
use App\Modules\Provisioning\Models\ProvisioningRun;

/**
 * Starting or inspecting a provisioning run creates and configures machines:
 * orga/admin only.
 */
class ProvisioningRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, ProvisioningRun $run): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }
}
