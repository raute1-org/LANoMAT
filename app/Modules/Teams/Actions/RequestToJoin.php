<?php

namespace App\Modules\Teams\Actions;

use App\Models\User;
use App\Modules\Teams\Enums\JoinRequestStatus;
use App\Modules\Teams\Exceptions\TeamException;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamJoinRequest;
use Illuminate\Database\QueryException;

class RequestToJoin
{
    public function handle(User $user, Team $team, ?string $message = null): TeamJoinRequest
    {
        if ($team->members()->where('user_id', $user->id)->exists()) {
            throw TeamException::alreadyMember();
        }

        $hasPending = $team->joinRequests()
            ->where('user_id', $user->id)
            ->where('status', JoinRequestStatus::Pending->value)
            ->exists();

        if ($hasPending) {
            throw TeamException::requestPending();
        }

        try {
            // A prior request may have reached either terminal state
            // (Accepted — e.g. the user later left the team via LeaveTeam
            // and is now re-requesting — or Declined). We keep no request
            // history, so remove both first: otherwise the new pending row's
            // eventual response could later collide with the stale terminal
            // row on (team_id, user_id, status) once it reaches that same
            // status (e.g. a second Accepted row after a former member
            // rejoins).
            $team->joinRequests()
                ->where('user_id', $user->id)
                ->whereIn('status', [JoinRequestStatus::Accepted->value, JoinRequestStatus::Declined->value])
                ->delete();

            return TeamJoinRequest::create([
                'team_id' => $team->id,
                'user_id' => $user->id,
                'message' => $message,
            ]);
        } catch (QueryException $e) {
            // Only a unique-key violation on (team_id, user_id, status) means
            // two concurrent requests raced past the check above. Any other
            // failure is a real error and must not be misreported.
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            throw TeamException::requestPending();
        }
    }
}
