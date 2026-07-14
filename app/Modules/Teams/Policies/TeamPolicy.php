<?php

namespace App\Modules\Teams\Policies;

use App\Models\User;
use App\Modules\Teams\Models\Team;

class TeamPolicy
{
    public function update(User $user, Team $team): bool
    {
        return $user->isOrga() || $team->owner_id === $user->id;
    }

    public function delete(User $user, Team $team): bool
    {
        return $this->update($user, $team);
    }

    public function manageMembers(User $user, Team $team): bool
    {
        return $this->update($user, $team);
    }
}
