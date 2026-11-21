<?php

namespace App\Modules\Registration\Policies;

use App\Models\User;
use App\Modules\Registration\Models\TicketType;

/**
 * Orga manages ticket types. "In use" and "last active" guards live in
 * TicketTypeGuard (the admin actions check them server-side), because the
 * admin Gate::before would bypass a policy-only guard.
 */
class TicketTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, TicketType $ticketType): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, TicketType $ticketType): bool
    {
        return $user->isOrga();
    }

    public function delete(User $user, TicketType $ticketType): bool
    {
        return $user->isOrga();
    }
}
