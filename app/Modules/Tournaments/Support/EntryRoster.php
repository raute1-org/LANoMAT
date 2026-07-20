<?php

namespace App\Modules\Tournaments\Support;

use App\Models\User;
use App\Modules\Tournaments\Listeners\NotifyRosterOnMatchReady;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Support\Collection;

/**
 * Resolves the {@see User} models behind a {@see TournamentEntry}'s roster —
 * every `roster_snapshot` member for a team entry, or the single `user` for
 * a solo entry — and the union of both of a {@see GameMatch}'s entries.
 *
 * Mirrors {@see TournamentEntry::rosterDiscordIds()} (which resolves the same
 * roster down to Discord ids for channel permission overwrites) but returns
 * full User models, since bell notifications need more than a Discord id.
 * Kept as a small, reusable resolver rather than inlined into a single
 * listener: {@see NotifyRosterOnMatchReady}
 * uses it today, and a later fix is expected to reuse it too.
 */
class EntryRoster
{
    /**
     * The user ids behind a single entry's roster — every `roster_snapshot`
     * member's `user_id` for a team entry, or the single `user_id` for a
     * solo entry — without issuing any query. Pulled out of {@see usersFor}
     * so callers that only need ids (e.g. friend-suggestion aggregation in
     * the Friends module) can skip the `User` query entirely.
     *
     * @return array<int, int>
     */
    public static function userIdsFor(TournamentEntry $entry): array
    {
        return $entry->roster_snapshot !== null
            ? array_column($entry->roster_snapshot, 'user_id')
            : array_filter([$entry->user_id]);
    }

    /**
     * @return Collection<int, User>
     */
    public static function usersFor(TournamentEntry $entry): Collection
    {
        $userIds = self::userIdsFor($entry);

        if ($userIds === []) {
            return collect();
        }

        return User::query()->whereIn('id', $userIds)->get();
    }

    /**
     * The distinct union of every given entry's roster users, resolved in a
     * single `User` query regardless of how many entries are given, keyed
     * by user id (so callers can look a user up in O(1) or call `->values()`
     * for a plain list). This is what makes {@see usersForMatch} and
     * {@see usersForTournament} single-query instead of one-`User`-query-per-entry.
     *
     * @param  Collection<int, TournamentEntry>  $entries
     * @return Collection<int, User>
     */
    public static function usersForEntries(Collection $entries): Collection
    {
        $userIds = $entries
            ->flatMap(fn (TournamentEntry $entry): array => self::userIdsFor($entry))
            ->unique()
            ->values()
            ->all();

        if ($userIds === []) {
            return collect();
        }

        return User::query()->whereIn('id', $userIds)->get()->keyBy('id');
    }

    /**
     * The distinct users across both of a match's entries (entry1 and
     * entry2 rosters combined, deduplicated by user id).
     *
     * @return Collection<int, User>
     */
    public static function usersForMatch(GameMatch $match): Collection
    {
        $entries = collect([$match->entry1, $match->entry2])->filter();

        return self::usersForEntries($entries)->values();
    }

    /**
     * The distinct users across every entry enrolled in the given
     * tournament (union of each entry's roster, deduplicated by user id).
     * Used to alarm affected tournament participants of a schedule change
     * (see `TournamentScheduleParticipantResolver`).
     *
     * @return Collection<int, User>
     */
    public static function usersForTournament(int $tournamentId): Collection
    {
        $entries = TournamentEntry::query()->where('tournament_id', $tournamentId)->get();

        return self::usersForEntries($entries)->values();
    }
}
