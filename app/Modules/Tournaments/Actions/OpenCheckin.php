<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Support\Facades\DB;

class OpenCheckin
{
    /**
     * Transition Enrollment -> CheckIn. A future `checkin_opens_at` is pulled
     * forward to now (manual early open), unless the window already closed.
     */
    public function handle(Tournament $tournament): Tournament
    {
        return DB::transaction(function () use ($tournament): Tournament {
            // Row lock like StartTournament, so a manual orga click racing the
            // scheduler tick (or a start) cannot double-transition.
            $tournament = Tournament::query()->whereKey($tournament->getKey())->lockForUpdate()->firstOrFail();

            if ($tournament->status !== TournamentStatus::Enrollment) {
                throw TournamentException::notInEnrollment();
            }

            // A manual early open must really open the window: CheckInEntry
            // still enforces checkin_opens_at, so a future value would show
            // the button but refuse every check-in. The scheduler path only
            // gets here once the opening has passed, so it never clamps.
            if ($tournament->checkin_opens_at !== null && $tournament->checkin_opens_at->isFuture()) {
                if ($tournament->checkin_closes_at !== null && $tournament->checkin_closes_at->lte(now())) {
                    throw TournamentException::checkinWindowAlreadyClosed();
                }

                $tournament->checkin_opens_at = now();
            }

            $tournament->status = TournamentStatus::CheckIn;
            $tournament->save();

            return $tournament;
        });
    }
}
