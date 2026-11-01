<?php

declare(strict_types=1);

namespace App\Modules\Tournaments\Support;

use App\Models\User;
use App\Modules\Tournaments\Actions\CheckInEntry;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\ReportStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Support\Facades\Gate;

/**
 * The viewer's own side of a tournament: which entry is theirs, which pending result
 * claim a match has, and what they may do right now. Shared by the participant web
 * controller and the native client API so that both resolve "my entry" and "may I act"
 * identically. Authorization always goes through the policies, via `Gate::forUser`
 * (a bearer-authenticated request has no default-guard user).
 */
class MyTournamentState
{
    /** The viewer's own entry (directly owned, or via team ownership), or null. */
    public static function entryFor(Tournament $tournament, User $user): ?TournamentEntry
    {
        return TournamentEntry::query()
            ->where('tournament_id', $tournament->id)
            ->where('status', '!=', EntryStatus::Withdrawn->value)
            ->ownedBy($user)
            ->latest('id')
            ->first();
    }

    /** Display-only: the viewer's team's entry when they are a plain member. */
    public static function rosteredEntryFor(Tournament $tournament, User $user): ?TournamentEntry
    {
        return TournamentEntry::query()
            ->where('tournament_id', $tournament->id)
            ->where('status', '!=', EntryStatus::Withdrawn->value)
            ->rosteredBy($user)
            ->with('team')
            ->latest('id')
            ->first();
    }

    /**
     * Which of the match's two entries belongs to `$user` (directly or via team ownership):
     * the entry the write Actions act on behalf of.
     */
    public static function entryForMatch(GameMatch $match, User $user): ?TournamentEntry
    {
        foreach ([$match->entry1, $match->entry2] as $entry) {
            if ($entry === null) {
                continue;
            }

            if ($entry->user_id === $user->id || $entry->team?->owner_id === $user->id) {
                return $entry;
            }
        }

        return null;
    }

    /** The match's pending result claim. Scoped to Pending: a match can accumulate several reports. */
    public static function pendingReportFor(GameMatch $match): ?MatchReport
    {
        return MatchReport::query()
            ->where('match_id', $match->id)
            ->where('status', ReportStatus::Pending->value)
            ->latest('id')
            ->first();
    }

    /**
     * @return array{id: int, status: string, canWithdraw: bool, isOwner: bool}|null
     */
    public static function entryState(?User $user, ?TournamentEntry $entry): ?array
    {
        if ($user === null || $entry === null) {
            return null;
        }

        return [
            'id' => $entry->id,
            'status' => $entry->status->value,
            'canWithdraw' => Gate::forUser($user)->allows('withdraw', $entry),
            // False for a plain team member: shown as entered, nothing to act on.
            'isOwner' => $entry->user_id === $user->id || $entry->team?->owner_id === $user->id,
        ];
    }

    /** Whether the entry may check in right now: open window, not yet checked in, policy and registration. */
    public static function canCheckIn(User $user, TournamentEntry $entry, Tournament $tournament): bool
    {
        return $entry->status === EntryStatus::Registered
            && CheckInEntry::windowIsOpen($tournament)
            && EventRegistrationRequirement::satisfiedBy($user, $tournament)
            && Gate::forUser($user)->allows('checkIn', $entry);
    }

    /**
     * What the viewer can do with a match, from the same policies the web actions use. A user
     * without an owned entry in the match (a plain team member, an orga viewing) gets false for
     * everything: the actions act on behalf of an entry, so there is nothing to act as.
     *
     * @return array{report: bool, confirm: bool, dispute: bool, withdrawReport: bool}
     */
    public static function matchAbilities(User $user, GameMatch $match): array
    {
        $none = ['report' => false, 'confirm' => false, 'dispute' => false, 'withdrawReport' => false];

        if (self::entryForMatch($match, $user) === null) {
            return $none;
        }

        $gate = Gate::forUser($user);
        $canReport = in_array($match->status, [MatchStatus::Ready, MatchStatus::Live], true) && $gate->allows('report', $match);

        $report = $match->status === MatchStatus::Reported ? self::pendingReportFor($match) : null;

        return [
            'report' => $canReport,
            'confirm' => $report !== null && $gate->allows('confirm', $report),
            'dispute' => $report !== null && $gate->allows('dispute', $report),
            'withdrawReport' => $report !== null && $gate->allows('withdrawReport', $report),
        ];
    }
}
