<?php

declare(strict_types=1);

namespace App\Modules\Stats\Support;

use App\Models\User;
use App\Modules\Stats\Enums\CompetitorKind;
use App\Modules\Teams\Models\Team;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Support\Collection;

/**
 * Cross-event leaderboard aggregate, built entirely from the existing
 * tournament data (`tournaments` / `tournament_entries` / `matches`) — no
 * new tables (roadmap 6.5). A "competitor" is either a `Team` or a `User`
 * (never both: `TournamentEntry` has a DB check constraint that exactly one
 * of `team_id`/`user_id` is set), so every aggregate here groups by the
 * composite identity `(type, id)` rather than the entry id, to correctly
 * fold a competitor's entries across different events/tournaments without
 * ever conflating a team with one of its members.
 *
 * All four metrics are computed with grouped DB aggregates (one query per
 * metric, each doing its own GROUP BY), then merged in PHP by competitor
 * key — this keeps the query count constant regardless of how many
 * competitors or tournaments exist, avoiding the N+1 that a naive
 * "loop entries, count their matches" implementation would produce.
 */
class LeaderboardQuery
{
    /**
     * @return list<array{id: int, type: string, name: string, wins: int, tournamentWins: int, participations: int, podiums: int, badges: list<string>}>
     */
    public static function topEntrants(int $limit = 25): array
    {
        $participations = self::participationCounts();
        $wins = self::winCounts();
        $tournamentWins = self::tournamentWinCounts();
        $podiums = $tournamentWins; // champion is always a podium finish

        $keys = collect($participations->keys())
            ->merge($wins->keys())
            ->merge($tournamentWins->keys())
            ->unique();

        $rows = $keys->map(function (string $key) use ($participations, $wins, $tournamentWins, $podiums): array {
            [$type, $id] = explode(':', $key, 2);
            $type = CompetitorKind::from($type);
            $id = (int) $id;

            return [
                'id' => $id,
                'type' => $type,
                'wins' => $wins->get($key, 0),
                'tournamentWins' => $tournamentWins->get($key, 0),
                'participations' => $participations->get($key, 0),
                'podiums' => $podiums->get($key, 0),
            ];
        });

        $rows = $rows->sortByDesc('wins')->values()->take($limit);

        $names = self::namesFor($rows);

        $result = $rows->map(function (array $row) use ($names): array {
            $key = $row['type']->value.':'.$row['id'];

            return [
                'id' => $row['id'],
                'type' => $row['type']->value,
                'name' => $names->get($key, '—'),
                'wins' => $row['wins'],
                'tournamentWins' => $row['tournamentWins'],
                'participations' => $row['participations'],
                'podiums' => $row['podiums'],
                'badges' => BadgeCalculator::for($row['id'], $row['type']),
            ];
        })->all();

        return array_values($result);
    }

    /**
     * Distinct tournaments entered, per competitor — one row per
     * (tournament, competitor), so counting entries already gives distinct
     * tournament participation (an entry belongs to exactly one tournament).
     *
     * @return Collection<string, int>
     */
    private static function participationCounts(): Collection
    {
        $userCounts = TournamentEntry::query()
            ->whereNotNull('user_id')
            ->selectRaw('user_id, count(distinct tournament_id) as aggregate')
            ->groupBy('user_id')
            ->pluck('aggregate', 'user_id');

        $teamCounts = TournamentEntry::query()
            ->whereNotNull('team_id')
            ->selectRaw('team_id, count(distinct tournament_id) as aggregate')
            ->groupBy('team_id')
            ->pluck('aggregate', 'team_id');

        return self::mergeKeyed($userCounts, $teamCounts);
    }

    /**
     * Completed matches won, per competitor, resolved via the winning
     * entry's `(team_id|user_id)`.
     *
     * @return Collection<string, int>
     */
    private static function winCounts(): Collection
    {
        $base = GameMatch::query()
            ->join('tournament_entries', 'tournament_entries.id', '=', 'matches.winner_entry_id')
            ->where('matches.status', MatchStatus::Completed);

        $userCounts = (clone $base)
            ->whereNotNull('tournament_entries.user_id')
            ->selectRaw('tournament_entries.user_id as competitor_id, count(*) as aggregate')
            ->groupBy('tournament_entries.user_id')
            ->pluck('aggregate', 'competitor_id');

        $teamCounts = (clone $base)
            ->whereNotNull('tournament_entries.team_id')
            ->selectRaw('tournament_entries.team_id as competitor_id, count(*) as aggregate')
            ->groupBy('tournament_entries.team_id')
            ->pluck('aggregate', 'competitor_id');

        return self::mergeKeyed($userCounts, $teamCounts);
    }

    /**
     * Tournament championships, per competitor, resolved via
     * `tournaments.winner_entry_id`.
     *
     * @return Collection<string, int>
     */
    private static function tournamentWinCounts(): Collection
    {
        $base = Tournament::query()
            ->join('tournament_entries', 'tournament_entries.id', '=', 'tournaments.winner_entry_id');

        $userCounts = (clone $base)
            ->whereNotNull('tournament_entries.user_id')
            ->selectRaw('tournament_entries.user_id as competitor_id, count(*) as aggregate')
            ->groupBy('tournament_entries.user_id')
            ->pluck('aggregate', 'competitor_id');

        $teamCounts = (clone $base)
            ->whereNotNull('tournament_entries.team_id')
            ->selectRaw('tournament_entries.team_id as competitor_id, count(*) as aggregate')
            ->groupBy('tournament_entries.team_id')
            ->pluck('aggregate', 'competitor_id');

        return self::mergeKeyed($userCounts, $teamCounts);
    }

    /**
     * @param  Collection<int|string, int>  $userCounts  keyed by user_id
     * @param  Collection<int|string, int>  $teamCounts  keyed by team_id
     * @return Collection<string, int> keyed by "user:{id}" / "team:{id}"
     */
    private static function mergeKeyed(Collection $userCounts, Collection $teamCounts): Collection
    {
        $merged = collect();

        foreach ($userCounts as $id => $count) {
            $merged->put('user:'.$id, (int) $count);
        }

        foreach ($teamCounts as $id => $count) {
            $merged->put('team:'.$id, (int) $count);
        }

        return $merged;
    }

    /**
     * Batch-resolves display names for the given rows in exactly two
     * queries (one for users, one for teams) — never per-row.
     *
     * @param  Collection<int, array{id: int, type: CompetitorKind, wins: int, tournamentWins: int, participations: int, podiums: int}>  $rows
     * @return Collection<string, string> keyed by "user:{id}" / "team:{id}"
     */
    private static function namesFor(Collection $rows): Collection
    {
        $userIds = $rows->where('type', CompetitorKind::User)->pluck('id')->unique()->values();
        $teamIds = $rows->where('type', CompetitorKind::Team)->pluck('id')->unique()->values();

        $names = collect();

        User::query()->whereIn('id', $userIds)->pluck('name', 'id')
            ->each(function (string $name, int $id) use ($names): void {
                $names->put('user:'.$id, $name);
            });

        Team::query()->whereIn('id', $teamIds)->pluck('name', 'id')
            ->each(function (string $name, int $id) use ($names): void {
                $names->put('team:'.$id, $name);
            });

        return $names;
    }
}
