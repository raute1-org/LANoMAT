<?php

namespace App\Modules\Tournaments\Support;

use App\Modules\Tournaments\Domain\RoundRobinStandings;
use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;

/**
 * Read model: the round-robin table of a tournament (null for every other
 * format), derived by the pure {@see RoundRobinStandings} calculator from the
 * persisted matches. Names only — no user data.
 */
class StandingsView
{
    /**
     * @return list<array{rank: int, entryId: int, name: string, played: int, wins: int, losses: int, pointsFor: int, pointsAgainst: int, scoreDifference: int}>|null
     */
    public static function forTournament(Tournament $tournament): ?array
    {
        if ($tournament->format !== TournamentFormat::RoundRobin) {
            return null;
        }

        $rows = GameMatch::query()->where('tournament_id', $tournament->id)->get()->keyBy('id');

        if ($rows->isEmpty()) {
            return [];
        }

        $plan = app(MatchProgression::class)->reconstruct($rows);

        $names = TournamentEntry::query()
            ->whereIn('id', $rows->pluck('entry1_id')->merge($rows->pluck('entry2_id'))->filter()->unique())
            ->pluck('display_name', 'id');

        return array_map(fn ($row): array => [
            'rank' => $row->rank,
            'entryId' => $row->entryId,
            'name' => (string) ($names[$row->entryId] ?? '—'),
            'played' => $row->played,
            'wins' => $row->wins,
            'losses' => $row->losses,
            'pointsFor' => $row->pointsFor,
            'pointsAgainst' => $row->pointsAgainst,
            'scoreDifference' => $row->scoreDifference,
        ], RoundRobinStandings::fromPlan($plan));
    }
}
