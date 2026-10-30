<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Support\Facades\DB;

/**
 * Announced/Enrollment/CheckIn -> Draft, only while no non-withdrawn entry exists.
 * Locks the tournament row first (same order as EnrollSolo/StartTournament),
 * so it serializes against a concurrent enrollment: either the entry lands
 * first and this refuses, or this wins and the enrollment is rejected.
 */
class ReopenToDraft
{
    public function handle(Tournament $tournament): Tournament
    {
        return DB::transaction(function () use ($tournament): Tournament {
            $tournament = Tournament::query()->whereKey($tournament->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($tournament->status, [TournamentStatus::Announced, TournamentStatus::Enrollment, TournamentStatus::CheckIn], true)) {
                throw TournamentException::cannotReturnToDraft();
            }

            $hasEntries = TournamentEntry::query()
                ->where('tournament_id', $tournament->id)
                ->where('status', '!=', EntryStatus::Withdrawn->value)
                ->exists();

            if ($hasEntries) {
                throw TournamentException::hasEntries();
            }

            $tournament->status = TournamentStatus::Draft;
            $tournament->save();

            return $tournament;
        });
    }
}
