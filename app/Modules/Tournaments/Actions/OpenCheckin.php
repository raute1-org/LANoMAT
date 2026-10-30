<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Support\Facades\DB;

class OpenCheckin
{
    /**
     * Transition Enrollment -> CheckIn.
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

            $tournament->status = TournamentStatus::CheckIn;
            $tournament->save();

            return $tournament;
        });
    }
}
