<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;

class CloseCheckin
{
    /**
     * Transition CheckIn -> Live once the check-in window has closed.
     *
     * Note: this only flips the lifecycle status. Bracket generation and
     * seeding is a separate concern owned by `StartTournament` (roadmap
     * 3.11), which is guarded independently (e.g. against regenerating an
     * already-seeded bracket) and is out of scope for this action.
     */
    public function handle(Tournament $tournament): Tournament
    {
        if ($tournament->status !== TournamentStatus::CheckIn) {
            throw TournamentException::notInEnrollment();
        }

        $tournament->status = TournamentStatus::Live;
        $tournament->save();

        return $tournament;
    }
}
