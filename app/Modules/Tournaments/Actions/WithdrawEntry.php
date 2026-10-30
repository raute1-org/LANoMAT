<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws an entry while enrollment or check-in is open. Withdrawn entries do
 * not count toward `max_entries`, so the slot is free again immediately.
 *
 * Locks the PARENT tournament row first (same order as EnrollSolo/StartTournament)
 * so a withdrawal and a start serialize: whichever commits second sees the other's
 * outcome, and a withdrawal can never slip into a bracket that is already seeded.
 *
 * Authorization (own entry / team owner) lives in `TournamentPolicy::withdraw`;
 * the Filament admin action authorizes via `manage` and reuses this Action.
 */
class WithdrawEntry
{
    public function handle(TournamentEntry $entry): TournamentEntry
    {
        return DB::transaction(function () use ($entry): TournamentEntry {
            $tournament = Tournament::query()->whereKey($entry->tournament_id)->lockForUpdate()->firstOrFail();

            if ($tournament->status === TournamentStatus::Live) {
                throw TournamentException::alreadyStarted();
            }

            if (! in_array($tournament->status, [TournamentStatus::Enrollment, TournamentStatus::CheckIn], true)) {
                throw TournamentException::withdrawalClosed();
            }

            $entry = TournamentEntry::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();

            if ($entry->status === EntryStatus::Withdrawn) {
                return $entry;
            }

            $entry->status = EntryStatus::Withdrawn;
            $entry->checked_in_at = null;
            $entry->save();

            return $entry;
        });
    }
}
