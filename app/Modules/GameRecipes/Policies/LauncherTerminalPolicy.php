<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Policies;

use App\Models\User;
use App\Modules\GameRecipes\Models\LauncherTerminal;

/**
 * Terminals are orga infrastructure: participants never see the fleet in the
 * panel or the API, and nobody edits a row by hand — the launcher heartbeat is
 * the only writer. The list carries the paired user's name, so it is orga-only
 * for the same reason the host registry is.
 */
class LauncherTerminalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, LauncherTerminal $terminal): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, LauncherTerminal $terminal): bool
    {
        return false;
    }

    public function delete(User $user, LauncherTerminal $terminal): bool
    {
        return $user->isOrga();
    }

    /** Filament's bulk delete authorizes `deleteAny`, not one record at a time. */
    public function deleteAny(User $user): bool
    {
        return $user->isOrga();
    }
}
