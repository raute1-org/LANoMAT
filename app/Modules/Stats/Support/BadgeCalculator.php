<?php

declare(strict_types=1);

namespace App\Modules\Stats\Support;

use App\Modules\Stats\Enums\CompetitorKind;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\TournamentEntry;

/**
 * Computed badges for a single competitor (user or team) — never stored,
 * always derived on read from the same `matches`/`tournament_entries` data
 * {@see LeaderboardQuery} aggregates, so there is nothing to keep in sync.
 */
class BadgeCalculator
{
    /**
     * @param  CompetitorKind|'user'|'team'  $kind
     * @return list<string>
     */
    public static function for(int $competitorId, CompetitorKind|string $kind): array
    {
        $kind = is_string($kind) ? CompetitorKind::from($kind) : $kind;
        $column = $kind === CompetitorKind::Team ? 'team_id' : 'user_id';

        $winsPerTournament = GameMatch::query()
            ->join('tournament_entries', 'tournament_entries.id', '=', 'matches.winner_entry_id')
            ->where('matches.status', MatchStatus::Completed)
            ->where('tournament_entries.'.$column, $competitorId)
            ->selectRaw('matches.tournament_id, count(*) as wins')
            ->groupBy('matches.tournament_id')
            ->pluck('wins', 'tournament_id');

        $totalWins = (int) $winsPerTournament->sum();
        $maxWinsInOneTournament = (int) $winsPerTournament->max();

        $participations = TournamentEntry::query()
            ->where($column, $competitorId)
            ->distinct('tournament_id')
            ->count('tournament_id');

        $badges = [];

        if ($totalWins >= 1) {
            $badges[] = 'first_win';
        }

        if ($maxWinsInOneTournament >= 3) {
            $badges[] = 'hattrick';
        }

        if ($participations >= 3) {
            $badges[] = 'veteran';
        }

        return $badges;
    }
}
