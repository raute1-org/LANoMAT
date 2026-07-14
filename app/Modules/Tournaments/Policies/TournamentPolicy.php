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
