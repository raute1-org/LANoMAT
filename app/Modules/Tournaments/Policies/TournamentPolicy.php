<?php

namespace App\Modules\Tournaments\Policies;

use App\Models\User;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;

class TournamentPolicy
{
    /**
     * Resource-level CRUD for the Filament admin panel. The panel is
     * already orga-only (`canAccessPanel`), so these simply delegate to
     * {@see manage()} for defense-in-depth rather than duplicating logic —
     * mirroring `EventPolicy`/`SeatPolicy`'s explicit CRUD methods instead of
     * relying on Filament's "no policy method = allow" fallback.
     */
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, Tournament $tournament): bool
    {
        return $this->manage($user, $tournament);
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, Tournament $tournament): bool
    {
        return $this->manage($user, $tournament);
    }

    public function delete(User $user, Tournament $tournament): bool
    {
        return $this->manage($user, $tournament);
    }

    public function enroll(User $user, Tournament $tournament): bool
    {
        return $tournament->status === TournamentStatus::Enrollment;
    }

    public function checkIn(User $user, TournamentEntry $entry): bool
    {
        return $user->isOrga()
            || $entry->user_id === $user->id
            || $entry->team?->owner_id === $user->id;
    }

    public function manage(User $user, Tournament $tournament): bool
    {
        return $user->isOrga();
    }

    /**
     * Reporting a result: the user must own (directly or via team ownership)
     * one of the match's two participating entries. Unlike confirm/dispute,
     * there is no "not the reporter" restriction here — either participant
     * may be the first to submit a report (see {@see SubmitMatchReport},
     * which is guarded only by match status, not identity).
     */
    public function report(User $user, GameMatch $match): bool
    {
        if ($user->isOrga()) {
            return true;
        }

        foreach ([$match->entry1, $match->entry2] as $entry) {
            if ($entry === null) {
                continue;
            }

            if ($entry->user_id === $user->id || $entry->team?->owner_id === $user->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Confirming a report: the domain rule is "the opponent confirms" — the
     * user must own (directly or via team ownership) the match's OTHER
     * participant, i.e. not the entry that submitted `$report`. Orgas may
     * always confirm, consistent with {@see manage()}.
     */
    public function confirm(User $user, MatchReport $report): bool
    {
        return $user->isOrga() || $this->ownsOpponentEntry($user, $report);
    }

    /**
     * Disputing a report follows the same participant-and-not-reporter rule
     * as confirming — see {@see confirm()}.
     */
    public function dispute(User $user, MatchReport $report): bool
    {
        return $user->isOrga() || $this->ownsOpponentEntry($user, $report);
    }

    private function ownsOpponentEntry(User $user, MatchReport $report): bool
    {
        $match = GameMatch::query()->find($report->match_id);

        if ($match === null) {
            return false;
        }

        foreach ([$match->entry1, $match->entry2] as $entry) {
            if ($entry === null) {
                continue;
            }

            $ownsEntry = $entry->user_id === $user->id || $entry->team?->owner_id === $user->id;

            if (! $ownsEntry) {
                continue;
            }

            $entryUserId = $entry->user_id ?? $entry->team?->owner_id;

            return $entryUserId !== $report->reported_by;
        }

        return false;
    }
}
