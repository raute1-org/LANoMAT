<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Support\TournamentSetupCheck;
use Illuminate\Support\Facades\DB;

/**
 * Announced -> Enrollment (and, as a deliberate shortcut for small LANs, Draft
 * -> Enrollment in one step: the same validation runs, so skipping the
 * announcement is harmless). Participants can only enroll in `enrollment`, so
 * this is the one code path that opens sign-ups.
 *
 * Validates via {@see TournamentSetupCheck} that everything participants and
 * the bracket engine rely on is configured. Locks the row like
 * {@see StartTournament}.
 */
class OpenTournamentEnrollment
{
    public function handle(Tournament $tournament): Tournament
    {
        return DB::transaction(function () use ($tournament): Tournament {
            $tournament = Tournament::query()->whereKey($tournament->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($tournament->status, [TournamentStatus::Draft, TournamentStatus::Announced], true)) {
                throw TournamentException::notAnnounced();
            }

            $missing = TournamentSetupCheck::missingFields($tournament);

            if ($missing !== []) {
                throw TournamentException::incompleteSetup($missing);
            }

            $tournament->status = TournamentStatus::Enrollment;
            $tournament->save();

            return $tournament;
        });
    }
}
