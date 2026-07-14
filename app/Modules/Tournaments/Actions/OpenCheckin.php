<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;

class OpenCheckin
{
    /**
     * Transition Enrollment -> CheckIn.
     */
    public function handle(Tournament $tournament): Tournament
    {
        if ($tournament->status !== TournamentStatus::Enrollment) {
            throw TournamentException::notInEnrollment();
        }

        $tournament->status = TournamentStatus::CheckIn;
        $tournament->save();

        return $tournament;
    }
}
