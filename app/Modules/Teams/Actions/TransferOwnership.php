<?php

namespace App\Modules\Teams\Actions;

use App\Models\User;
use App\Modules\Teams\Enums\TeamRole;
use App\Modules\Teams\Exceptions\TeamException;
use App\Modules\Teams\Models\Team;
use Illuminate\Support\Facades\DB;

class TransferOwnership
{
    public function handle(Team $team, User $newOwner): Team
    {
        return DB::transaction(function () use ($team, $newOwner): Team {
            $newMember = $team->members()->where('user_id', $newOwner->id)->first();

            if ($newMember === null) {
                throw TeamException::notAMember();
            }

            $oldOwnerMember = $team->members()->where('user_id', $team->owner_id)->first();
            if ($oldOwnerMember !== null) {
                $oldOwnerMember->role = TeamRole::Member;
                $oldOwnerMember->save();
            }

            $newMember->role = TeamRole::Owner;
            $newMember->save();

            $team->owner_id = $newOwner->id;
            $team->save();

            return $team;
        });
    }
}
