<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;

class WithdrawEntry
{
    public function handle(TournamentEntry $entry): TournamentEntry
    {
        $tournament = Tournament::query()->findOrFail($entry->tournament_id);

        if ($tournament->status === TournamentStatus::Live) {
            throw TournamentException::alreadyStarted();
        }

        $entry->status = EntryStatus::Withdrawn;
        $entry->save();

        return $entry;
    }
}
