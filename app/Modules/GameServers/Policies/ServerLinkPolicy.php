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
 *
 * Permanent servers (all year, independent of events) differ in two ways: any
 * logged-in user may see them on the participant page (`viewPermanent`), but
 * only admins manage them (`managePermanent` for the class-level abilities,
 * the record-level `view`/`power`/`update`/`delete` check the admin role for
 * a permanent link and stay orga-level for match/event servers).
 */
class ServerLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, ServerLink $link): bool
    {
        return $this->manages($user, $link);
    }

    /** The participant list of permanent servers: every logged-in user. */
    public function viewPermanent(User $user): bool
    {
        return true;
    }

    /** Creating a permanent server and opening its admin resource (class-level). */
    public function managePermanent(User $user): bool
    {
        return $user->isAdmin();
    }

    /** Starting a new server from a preset (class-level: there is no record yet). */
    public function start(User $user): bool
    {
        return $user->isOrga();
    }

    public function power(User $user, ServerLink $link): bool
    {
        return $this->manages($user, $link);
    }

    public function update(User $user, ServerLink $link): bool
    {
        return $this->manages($user, $link);
    }

    public function delete(User $user, ServerLink $link): bool
    {
        return $this->manages($user, $link);
    }

    private function manages(User $user, ServerLink $link): bool
    {
        return $link->permanent ? $user->isAdmin() : $user->isOrga();
    }
}
