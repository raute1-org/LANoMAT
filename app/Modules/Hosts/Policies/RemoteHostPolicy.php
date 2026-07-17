<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Policies;

use App\Models\User;
use App\Modules\Hosts\Models\RemoteHost;

/**
 * The remote-hosts registry is orga-only infra: it holds SSH credentials to
 * external machines, so even helpers (who may operate targeted participant-
 * facing surfaces like QR check-in) get no access here. Admins pass via the
 * Gate::before short-circuit in AppServiceProvider::configureAuthorization.
 */
class RemoteHostPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, RemoteHost $remoteHost): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, RemoteHost $remoteHost): bool
    {
        return $user->isOrga();
    }

    public function delete(User $user, RemoteHost $remoteHost): bool
    {
        return $user->isOrga();
    }
}
