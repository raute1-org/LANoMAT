<?php

namespace App\Modules\Tournaments\Support;

use App\Modules\Teams\Models\Team;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Support\Collection;

/**
 * Roster rules of team entries, checked against the team's CURRENT members:
 *
 *  - a person plays for one entry per tournament (as in solo enrollment),
 *  - the roster still has the size the tournament requires.
 *
 * A team may change after it enrolled (a member leaves, another joins), so
 * check-in and start re-validate instead of trusting the enrollment-time
 * `roster_snapshot`; a valid check refreshes the snapshot to the real roster.
 * (Chosen over blocking `LeaveTeam` while entered: it keeps the Teams module
 * free of any Tournaments dependency and still guarantees nobody plays with a
 * broken roster.)
 */
class TeamRosterGuard
{
    /**
     * @param  array<int, int>  $userIds
     */
    public function assertNoSharedPlayers(Tournament $tournament, array $userIds, ?int $exceptEntryId = null): void
    {
        $others = TournamentEntry::query()
            ->where('tournament_id', $tournament->id)
            ->where('status', '!=', EntryStatus::Withdrawn->value)
            ->whereNotNull('team_id')
            ->when($exceptEntryId !== null, fn ($query) => $query->where('id', '!=', $exceptEntryId))
            ->get();

        $this->assertDisjoint($userIds, $others);
    }

    /**
     * Re-validates a team entry against its team's current members and
     * refreshes its roster snapshot. No-op for solo entries.
     */
    public function revalidate(Tournament $tournament, TournamentEntry $entry): void
    {
        if ($entry->team_id === null) {
            return;
        }

        $team = Team::query()->with('members.user')->find($entry->team_id);

        if ($team === null || $team->members->count() !== $tournament->team_size) {
            throw TournamentException::entryRosterInvalid();
        }

        $userIds = $team->members->pluck('user_id')->map(fn ($id): int => (int) $id)->values()->all();

        $this->assertNoSharedPlayers($tournament, $userIds, $entry->id);

        $entry->roster_snapshot = $team->members
            ->map(fn ($member): array => ['user_id' => $member->user_id, 'name' => ($member->user->name ?? '')])
            ->all();
        $entry->save();
    }

    /**
     * Start-time check over the entries that actually take part: every team
     * roster is still complete and no person appears in two of them.
     *
     * @param  Collection<int, TournamentEntry>  $entries
     */
    public function revalidateAll(Tournament $tournament, Collection $entries): void
    {
        $teamEntries = $entries->filter(fn (TournamentEntry $entry): bool => $entry->team_id !== null);
        $seen = [];

        foreach ($teamEntries as $entry) {
            $this->revalidateSizeOnly($tournament, $entry);

            foreach (EntryRoster::userIdsFor($entry) as $userId) {
                if (isset($seen[$userId])) {
                    throw TournamentException::playerInMultipleEntries();
                }

                $seen[$userId] = true;
            }
        }
    }

    private function revalidateSizeOnly(Tournament $tournament, TournamentEntry $entry): void
    {
        $team = Team::query()->with('members.user')->find($entry->team_id);

        if ($team === null || $team->members->count() !== $tournament->team_size) {
            throw TournamentException::entryRosterInvalid();
        }

        $entry->roster_snapshot = $team->members
            ->map(fn ($member): array => ['user_id' => $member->user_id, 'name' => ($member->user->name ?? '')])
            ->all();
        $entry->save();
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  Collection<int, TournamentEntry>  $entries
     */
    private function assertDisjoint(array $userIds, Collection $entries): void
    {
        foreach ($entries as $other) {
            if (array_intersect($userIds, array_map('intval', EntryRoster::userIdsFor($other))) !== []) {
                throw TournamentException::playerInMultipleEntries();
            }
        }
    }
}
