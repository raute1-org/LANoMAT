<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Policies;

use App\Models\User;
use App\Modules\GameServers\Models\ServerLink;

/**
 * Server links are orga-managed provisioning state (see ServerLink's own
 * comment: pelican_server_id/join_info/status are never client-fillable) —
 * mirrors GamePolicy's all-orga shape. `power` gates the Filament
 * Start/Stop/Restart actions, distinct from `update`/`delete` since it does
 * not touch the record's own fields directly but proxies to PelicanClient.
 */
class ServerLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, ServerLink $link): bool
    {
        return $user->isOrga();
    }

    public function power(User $user, ServerLink $link): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, ServerLink $link): bool
    {
        return $user->isOrga();
    }

    public function delete(User $user, ServerLink $link): bool
    {
        return $user->isOrga();
    }
}
