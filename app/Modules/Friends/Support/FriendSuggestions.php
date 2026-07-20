<?php

declare(strict_types=1);

namespace App\Modules\Friends\Support;

use App\Models\User;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Models\UserBlock;
use App\Modules\Identity\Contracts\LinkedAccountConnector;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Support\LinkedAccountConnectors;
use App\Modules\Presence\Support\PresenceProjection;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Teams\Models\TeamMember;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Support\EntryRoster;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Pure, IO-free read-model of LAN-native friend suggestions: candidate
 * users who share at least one LAN context with the given user — being
 * co-registered at the same event, co-member of the same team, or a
 * co-entrant of the same tournament (solo entries share the tournament
 * directly, team entries share it via the enrolled team's roster, see
 * {@see EntryRoster::usersFor()}) — excluding self, current friends, users
 * with a pending friend request in either direction, and users blocked in
 * either direction.
 *
 * Every cross-module fact is read through the owning module's own Eloquent
 * models ({@see EventRegistration}, {@see TeamMember},
 * {@see TournamentEntry}) — never a raw query against another module's
 * table — mirroring the {@see PresenceProjection}
 * precedent for cross-module read-models.
 *
 * A fourth, cross-event source layers in Steam's own friend graph: if
 * `$user` has a linked Steam account, its Steam friends who are also
 * LANoMAT users (linked via their own {@see LinkedAccount}) count as a
 * shared context too — read through {@see LinkedAccountConnector::friendProviderIds()}
 * (never a direct Steam API call from this class).
 *
 * `shared` counts the number of distinct shared *entities* across all four
 * sources (e.g. 2 shared events + 1 shared team = 3), not the number
 * of distinct context *types* — this rewards users who cross paths with
 * `$user` more often with a higher rank. `reasons` is the distinct set of
 * context *types* that contributed at least one shared entity, using these
 * stable keys (mapped to German copy by the UI):
 * - `shared_event`: co-registered at the same event.
 * - `shared_team`: co-member of the same team.
 * - `shared_tournament`: co-entrant of the same tournament.
 * - `shared_steam_friend`: a mutual Steam friend who also uses LANoMAT.
 */
final class FriendSuggestions
{
    public function __construct(private readonly FriendService $friends) {}

    /**
     * @return Collection<int, array{user: User, shared: int, reasons: list<string>}>
     */
    public function for(User $user, int $limit = 20): Collection
    {
        $excludedUserIds = $this->excludedUserIds($user);

        // user_id => ['shared' => int, 'reasons' => array<string, true>]
        $index = [];

        $this->accumulate($index, $this->sharedEventUserIds($user, $excludedUserIds), 'shared_event');
        $this->accumulate($index, $this->sharedTeamUserIds($user, $excludedUserIds), 'shared_team');
        $this->accumulate($index, $this->sharedTournamentUserIds($user, $excludedUserIds), 'shared_tournament');
        $this->accumulate($index, $this->sharedSteamFriendUserIds($user, $excludedUserIds), 'shared_steam_friend');

        if ($index === []) {
            return collect();
        }

        $ranked = collect($index)
            ->sortByDesc('shared')
            ->take($limit);

        $users = User::query()->whereIn('id', $ranked->keys())->get()->keyBy('id');

        return $ranked
            ->map(function (array $entry, int $userId) use ($users): ?array {
                $candidate = $users->get($userId);

                if ($candidate === null) {
                    return null;
                }

                return [
                    'user' => $candidate,
                    'shared' => $entry['shared'],
                    'reasons' => array_keys($entry['reasons']),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Self + accepted friends + pending-either-direction + blocked-either-way,
     * built once and reused across all four context sources.
     *
     * @return array<int, int>
     */
    private function excludedUserIds(User $user): array
    {
        $friendIds = $this->friends->friendUserIds($user);

        $pendingIds = Friendship::query()
            ->where('status', FriendshipStatus::Pending)
            ->where(function ($query) use ($user): void {
                $query->where('requester_id', $user->id)->orWhere('addressee_id', $user->id);
            })
            ->get(['requester_id', 'addressee_id'])
            ->map(fn (Friendship $friendship): int => $friendship->requester_id === $user->id
                ? $friendship->addressee_id
                : $friendship->requester_id)
            ->all();

        $blockedIds = UserBlock::query()
            ->where('blocker_id', $user->id)
            ->orWhere('blocked_id', $user->id)
            ->get(['blocker_id', 'blocked_id'])
            ->map(fn (UserBlock $block): int => $block->blocker_id === $user->id
                ? $block->blocked_id
                : $block->blocker_id)
            ->all();

        return array_unique([$user->id, ...$friendIds, ...$pendingIds, ...$blockedIds]);
    }

    /**
     * Distinct co-attendee user ids per shared event id: `$user`'s own
     * event ids, then every other candidate registered at one of those
     * events, deduplicated per (event, user) pair.
     *
     * @param  array<int, int>  $excludedUserIds
     * @return Collection<int, int<0, max>> user id => count of distinct shared events
     */
    private function sharedEventUserIds(User $user, array $excludedUserIds): Collection
    {
        $eventIds = EventRegistration::query()
            ->where('user_id', $user->id)
            ->pluck('event_id');

        if ($eventIds->isEmpty()) {
            return collect();
        }

        return EventRegistration::query()
            ->whereIn('event_id', $eventIds)
            ->whereNotIn('user_id', $excludedUserIds)
            ->get(['event_id', 'user_id'])
            ->groupBy('user_id')
            ->mapWithKeys(fn (Collection $registrations, int $userId): array => [
                $userId => $registrations->pluck('event_id')->unique()->count(),
            ]);
    }

    /**
     * Distinct co-member user ids per shared team id.
     *
     * @param  array<int, int>  $excludedUserIds
     * @return Collection<int, int<0, max>> user id => count of distinct shared teams
     */
    private function sharedTeamUserIds(User $user, array $excludedUserIds): Collection
    {
        $teamIds = TeamMember::query()
            ->where('user_id', $user->id)
            ->pluck('team_id');

        if ($teamIds->isEmpty()) {
            return collect();
        }

        return TeamMember::query()
            ->whereIn('team_id', $teamIds)
            ->whereNotIn('user_id', $excludedUserIds)
            ->get(['team_id', 'user_id'])
            ->groupBy('user_id')
            ->mapWithKeys(fn (Collection $members, int $userId): array => [
                $userId => $members->pluck('team_id')->unique()->count(),
            ]);
    }

    /**
     * Distinct co-entrant user ids per shared tournament id. Solo entries
     * contribute their `user_id` directly; team entries contribute their
     * enrolled roster via {@see EntryRoster::usersFor()} (the
     * `roster_snapshot` captured at enrollment, falling back to the current
     * team membership only via that same helper).
     *
     * @param  array<int, int>  $excludedUserIds
     * @return Collection<int, int<0, max>> user id => count of distinct shared tournaments
     */
    private function sharedTournamentUserIds(User $user, array $excludedUserIds): Collection
    {
        $tournamentIds = TournamentEntry::query()
            ->where('user_id', $user->id)
            ->pluck('tournament_id')
            ->merge(
                TournamentEntry::query()
                    ->whereHas('team.members', fn ($query) => $query->where('user_id', $user->id))
                    ->pluck('tournament_id')
            )
            ->unique();

        if ($tournamentIds->isEmpty()) {
            return collect();
        }

        $entries = TournamentEntry::query()
            ->whereIn('tournament_id', $tournamentIds)
            ->get();

        // tournament_id => set of user ids (deduplicated within a tournament)
        $usersByTournament = $entries
            ->groupBy('tournament_id')
            ->map(fn (Collection $entries): Collection => $entries
                ->flatMap(fn (TournamentEntry $entry): Collection => EntryRoster::usersFor($entry))
                ->pluck('id')
                ->unique());

        // user_id => count of distinct shared tournaments
        /** @var array<int, int<0, max>> $counts */
        $counts = [];
        foreach ($usersByTournament as $userIds) {
            /** @var int $candidateId */
            foreach ($userIds as $candidateId) {
                if ($candidateId === $user->id || in_array($candidateId, $excludedUserIds, true)) {
                    continue;
                }

                $counts[$candidateId] = ($counts[$candidateId] ?? 0) + 1;
            }
        }

        return collect($counts);
    }

    /**
     * Mutual Steam friends of `$user` who are themselves LANoMAT users: the
     * external SteamID64 friend list is read once per (user, Steam account)
     * and cached for 15 minutes — see {@see LinkedAccountConnector::friendProviderIds()}
     * — since it is a best-effort, potentially slow third-party call. The
     * intersection against LANoMAT's own {@see LinkedAccount} table and the
     * `$excludedUserIds` exclusion are always applied live, never cached,
     * since friendships/blocks can change between cache windows.
     *
     * @param  array<int, int>  $excludedUserIds
     * @return Collection<int, int<0, max>> user id => count (always 0 or 1; a
     *                                      user has at most one Steam account)
     */
    private function sharedSteamFriendUserIds(User $user, array $excludedUserIds): Collection
    {
        $steam = $user->linkedAccount(LinkedAccountProvider::Steam);

        if ($steam === null) {
            return collect();
        }

        /** @var array<int, string> $friendSteamIds */
        $friendSteamIds = Cache::remember(
            "steam-friends:{$user->id}:{$steam->provider_user_id}",
            now()->addMinutes(15),
            fn (): array => app(LinkedAccountConnectors::class)->for(LinkedAccountProvider::Steam)->friendProviderIds($steam),
        );

        if ($friendSteamIds === []) {
            return collect();
        }

        return LinkedAccount::query()
            ->where('provider', LinkedAccountProvider::Steam)
            ->whereIn('provider_user_id', $friendSteamIds)
            ->whereNotIn('user_id', $excludedUserIds)
            ->get(['user_id'])
            ->groupBy('user_id')
            ->map(fn (Collection $accounts): int => $accounts->count());
    }

    /**
     * Merges a per-user shared-count collection for one context type into
     * the running index, tagging every contributing user with that type's
     * reason key.
     *
     * @param  array<int, array{shared: int, reasons: array<string, true>}>  $index
     * @param  Collection<int, int<0, max>>  $counts  user id => count of distinct shared entities
     */
    private function accumulate(array &$index, Collection $counts, string $reason): void
    {
        foreach ($counts as $userId => $count) {
            if (! isset($index[$userId])) {
                $index[$userId] = ['shared' => 0, 'reasons' => []];
            }

            $index[$userId]['shared'] += $count;
            $index[$userId]['reasons'][$reason] = true;
        }
    }
}
