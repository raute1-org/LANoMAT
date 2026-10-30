<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Support\TournamentSetupCheck;
use Illuminate\Support\Facades\DB;

/**
 * Draft -> Announced: the tournament becomes visible to every logged-in
 * participant (info, rules, bracket type, slots) while sign-up stays closed
 * until {@see OpenTournamentEnrollment}. Applies the same completeness check
 * as opening enrollment, so an announcement never promises an unusable
 * tournament. Locks the row like {@see OpenTournamentEnrollment}.
 */
class AnnounceTournament
{
    public function handle(Tournament $tournament): Tournament
    {
        return DB::transaction(function () use ($tournament): Tournament {
            $tournament = Tournament::query()->whereKey($tournament->getKey())->lockForUpdate()->firstOrFail();

            if ($tournament->status !== TournamentStatus::Draft) {
                throw TournamentException::notDraft();
            }

            $missing = TournamentSetupCheck::missingFields($tournament);

            if ($missing !== []) {
                throw TournamentException::incompleteSetup($missing);
            }

            $tournament->status = TournamentStatus::Announced;
            $tournament->save();

            return $tournament;
        });
    }
}
