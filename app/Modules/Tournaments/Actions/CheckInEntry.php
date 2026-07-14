<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class CheckInEntry
{
    public function handle(TournamentEntry $entry): TournamentEntry
    {
        $tournament = Tournament::query()->findOrFail($entry->tournament_id);

        if (! $this->windowIsOpen($tournament->status, $tournament->checkin_opens_at, $tournament->checkin_closes_at)) {
            throw TournamentException::checkinClosed();
        }

        $entry->status = EntryStatus::CheckedIn;
        $entry->checked_in_at = Carbon::now();
        $entry->save();

        return $entry;
    }

    private function windowIsOpen(TournamentStatus $status, ?CarbonInterface $opensAt, ?CarbonInterface $closesAt): bool
    {
        if ($status !== TournamentStatus::CheckIn) {
            return false;
        }

        $now = Carbon::now();

        if ($opensAt !== null && $now->lt($opensAt)) {
            return false;
        }

        if ($closesAt !== null && $now->gt($closesAt)) {
            return false;
        }

        return true;
    }
}
