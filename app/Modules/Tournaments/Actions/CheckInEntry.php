<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Support\TeamRosterGuard;
use Illuminate\Support\Carbon;

class CheckInEntry
{
    public function __construct(
        private readonly TeamRosterGuard $rosterGuard,
    ) {}

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

        if (! self::windowIsOpen($tournament)) {
            throw TournamentException::checkinClosed();
        }

        // The team may have changed since it enrolled (F-R2A-6): re-check the
        // current roster before the entry counts as checked in.
        $this->rosterGuard->revalidate($tournament, $entry);

        $entry->status = EntryStatus::CheckedIn;
        $entry->checked_in_at = Carbon::now();
        $entry->save();

        return $entry;
    }

    /** Whether the tournament currently accepts check-ins (status and time window). */
    public static function windowIsOpen(Tournament $tournament): bool
    {
        $status = $tournament->status;
        $opensAt = $tournament->checkin_opens_at;
        $closesAt = $tournament->checkin_closes_at;

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
