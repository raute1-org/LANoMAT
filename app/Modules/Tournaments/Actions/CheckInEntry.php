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
        // A Withdrawn entry must never be checked in again — without this
        // guard, only the time window gated check-in, so a withdrawn entry
        // could re-check-in during an open window and silently bypass the
        // withdrawal (and any capacity limit it freed up). An already
        // CheckedIn entry re-checking in is a harmless idempotent no-op.
        if ($entry->status === EntryStatus::Withdrawn) {
            throw TournamentException::entryWithdrawn();
        }

        if ($entry->status === EntryStatus::CheckedIn) {
            return $entry;
        }

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
