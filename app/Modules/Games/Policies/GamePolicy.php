<?php

namespace App\Modules\Games\Policies;

use App\Models\User;
use App\Modules\Games\Models\Game;

/**
 * The games catalog is orga-managed reference data (name, slug, team-size
 * bounds, Pelican egg, default server-config preset); there is no
 * participant-facing read surface for it — participants only ever see a
 * game's name via a tournament's relationship.
 */
class GamePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, Game $game): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, Game $game): bool
    {
        return $user->isOrga();
    }

    public function delete(User $user, Game $game): bool
    {
        return $user->isOrga();
    }
}
