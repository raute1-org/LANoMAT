<?php

namespace App\Modules\Tournaments\Actions;

use App\Models\User;
use App\Modules\Teams\Models\Team;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Support\Facades\DB;
use LogicException;

class EnrollTeam
{
    public function handle(Tournament $tournament, Team $team): TournamentEntry
    {
        if ($tournament->status !== TournamentStatus::Enrollment) {
            throw TournamentException::notInEnrollment();
        }

        return DB::transaction(function () use ($tournament, $team): TournamentEntry {
            // Lock the PARENT tournament row first (M2 lock-order convention,
            // see RegisterForEvent) so concurrent enrollments serialize on
            // this row before reading the capacity count below.
            $tournament = Tournament::query()->whereKey($tournament->getKey())->lockForUpdate()->firstOrFail();

            // Re-read the roster under the lock: the team's membership must
            // be checked against the same serialized snapshot as the
            // capacity count below, not an earlier unlocked read that could
            // race a concurrent roster change. `load()` (not `loadMissing`)
            // forces a fresh query even if members were eager-loaded before
            // the transaction started.
            $team->load('members.user');

            if ($team->members->count() !== $tournament->team_size) {
                throw TournamentException::rosterSizeMismatch();
            }

            $alreadyEnrolled = TournamentEntry::query()
                ->where('tournament_id', $tournament->id)
                ->where('team_id', $team->id)
                ->where('status', '!=', EntryStatus::Withdrawn->value)
                ->exists();

            if ($alreadyEnrolled) {
                throw TournamentException::alreadyEnrolled();
            }

            $activeCount = TournamentEntry::query()
                ->where('tournament_id', $tournament->id)
                ->where('status', '!=', EntryStatus::Withdrawn->value)
                ->count();

            if ($tournament->max_entries !== null && $activeCount >= $tournament->max_entries) {
                throw TournamentException::full();
            }

            $entry = new TournamentEntry([
                'tournament_id' => $tournament->id,
                'team_id' => $team->id,
                'display_name' => $team->name,
            ]);
            $entry->status = EntryStatus::Registered;
            $entry->roster_snapshot = $team->members
                ->map(function ($member): array {
                    $memberUser = $member->user;

                    // user_id is a non-nullable FK, so a missing related
                    // User here means the referenced row was hard-deleted
                    // out from under an active team roster — a data
                    // inconsistency, not a business-rule violation. Fail
                    // loudly instead of silently persisting an empty name
                    // into the roster snapshot.
                    if (! $memberUser instanceof User) {
                        throw new LogicException("Team member {$member->id} references a missing user (user_id={$member->user_id}).");
                    }

                    return [
                        'user_id' => $member->user_id,
                        'name' => $memberUser->name,
                    ];
                })
                ->all();
            $entry->save();

            return $entry;
        });
    }
}
