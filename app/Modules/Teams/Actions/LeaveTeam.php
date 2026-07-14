<?php

namespace App\Modules\Teams\Actions;

use App\Models\User;
use App\Modules\Teams\Exceptions\TeamException;
use App\Modules\Teams\Models\Team;

class LeaveTeam
{
    public function handle(User $user, Team $team): void
    {
        if ($team->owner_id === $user->id) {
            throw TeamException::ownerMustTransfer();
        }

        $team->members()->where('user_id', $user->id)->delete();
    }
}
