<?php

namespace App\Modules\Teams\Actions;

use App\Modules\Teams\Enums\JoinRequestStatus;
use App\Modules\Teams\Enums\TeamRole;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamJoinRequest;
use Illuminate\Support\Facades\DB;

class RespondToJoinRequest
{
    public function handle(TeamJoinRequest $request, bool $accept): TeamJoinRequest
    {
        return DB::transaction(function () use ($request, $accept): TeamJoinRequest {
            if ($accept) {
                $team = Team::query()->findOrFail($request->team_id);
                $member = $team->members()->where('user_id', $request->user_id)->first();

                if ($member === null) {
                    $member = $team->members()->make(['user_id' => $request->user_id]);
                    $member->role = TeamRole::Member;
                    $member->save();
                }
            }

            $request->status = $accept ? JoinRequestStatus::Accepted : JoinRequestStatus::Declined;
            $request->save();

            return $request;
        });
    }
}
