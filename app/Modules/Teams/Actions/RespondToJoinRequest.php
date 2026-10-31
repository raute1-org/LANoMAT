<?php

namespace App\Modules\Teams\Actions;

use App\Modules\Teams\Enums\JoinRequestStatus;
use App\Modules\Teams\Enums\TeamRole;
use App\Modules\Teams\Exceptions\TeamException;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamJoinRequest;
use App\Modules\Teams\Notifications\TeamJoinRequestAnswered;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class RespondToJoinRequest
{
    public function handle(TeamJoinRequest $request, bool $accept): TeamJoinRequest
    {
        try {
            $answered = DB::transaction(function () use ($request, $accept): TeamJoinRequest {
                // Re-read under a row lock: a request is answered once. A
                // second answer (double click, second tab) would otherwise
                // flip an accepted request to declined while the user stays
                // a member (F-R2A-9).
                $request = TeamJoinRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

                if ($request->status !== JoinRequestStatus::Pending) {
                    throw TeamException::requestAlreadyAnswered();
                }

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

            $team = $answered->team;
            if ($team !== null) {
                $answered->user?->notify(new TeamJoinRequestAnswered($team, $accept));
            }

            return $answered;
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
