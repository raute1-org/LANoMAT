<?php

namespace App\Modules\Teams\Actions;

use App\Modules\Teams\Enums\JoinRequestStatus;
use App\Modules\Teams\Enums\TeamRole;
use App\Modules\Teams\Exceptions\TeamException;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamJoinRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class RespondToJoinRequest
{
    public function handle(TeamJoinRequest $request, bool $accept): TeamJoinRequest
    {
        try {
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
        } catch (QueryException $e) {
            // Only a unique-key violation on (team_id, user_id, status) means
            // a stale/duplicate terminal row already exists for this pair.
            // Any other failure is a real error and must not be misreported.
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            throw TeamException::staleRequest();
        }
    }
}
