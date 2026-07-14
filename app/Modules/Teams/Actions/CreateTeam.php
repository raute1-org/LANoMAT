<?php

namespace App\Modules\Teams\Actions;

use App\Models\User;
use App\Modules\Teams\Enums\TeamRole;
use App\Modules\Teams\Models\Team;
use Illuminate\Support\Facades\DB;

class CreateTeam
{
    public function handle(User $owner, string $name, string $tag): Team
    {
        return DB::transaction(function () use ($owner, $name, $tag): Team {
            $team = new Team([
                'name' => $name,
                'tag' => $tag,
            ]);
            $team->owner_id = $owner->id;
            $team->save();

            $member = $team->members()->make(['user_id' => $owner->id]);
            $member->role = TeamRole::Owner;
            $member->save();

            return $team;
        });
    }
}
