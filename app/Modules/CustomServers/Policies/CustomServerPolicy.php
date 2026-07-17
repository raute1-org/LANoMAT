<?php

declare(strict_types=1);

namespace App\Modules\CustomServers\Policies;

use App\Models\User;
use App\Modules\CustomServers\Actions\ProbeCustomServer;
use App\Modules\CustomServers\Models\CustomServer;
use App\Modules\Hosts\Policies\RemoteHostPolicy;

/**
 * Custom docker servers run arbitrary docker commands on registered
 * remote-host infrastructure (SSH-reachable machines), so — like
 * {@see RemoteHostPolicy} — this is orga-only:
 * even helpers get no access here. Admins pass via the Gate::before
 * short-circuit in AppServiceProvider::configureAuthorization.
 */
class CustomServerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    /**
     * Also backs {@see ProbeCustomServer},
     * which is read-only (a `docker inspect` reachability check) and so is
     * gated the same as any other detail view rather than `start`/`stop`.
     */
    public function view(User $user, CustomServer $customServer): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, CustomServer $customServer): bool
    {
        return $user->isOrga();
    }

    public function delete(User $user, CustomServer $customServer): bool
    {
        return $user->isOrga();
    }

    public function start(User $user, CustomServer $customServer): bool
    {
        return $user->isOrga();
    }

    public function stop(User $user, CustomServer $customServer): bool
    {
        return $user->isOrga();
    }
}
