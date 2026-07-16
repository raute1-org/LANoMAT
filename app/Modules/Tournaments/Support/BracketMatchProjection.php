<?php

namespace App\Modules\Tournaments\Support;

use App\Modules\Infoscreen\Support\ScenePayload;
use App\Modules\Tournaments\Http\TournamentPageController;
use App\Modules\Tournaments\Models\GameMatch;
use Illuminate\Support\Collection;

/**
 * The single `GameMatch` -> wire DTO projection (`BracketMatchDto` on the
 * frontend) shared by the tournament show page's bracket
 * ({@see TournamentPageController}) and the
 * infoscreen's Bracket/UpcomingMatches scenes
 * ({@see ScenePayload}). Keeping this in one
 * place means both surfaces can never drift on shape.
 */
class BracketMatchProjection
{
    /**
     * @return array<string, mixed>
     */
    public static function fromMatch(GameMatch $match): array
    {
        return [
            'id' => $match->id,
            'round' => $match->round,
            'bracket' => $match->bracket,
            'position' => $match->position,
            'nextMatchId' => $match->next_match_id,
            'nextSlot' => $match->next_slot,
            'slot1' => $match->entry1?->display_name,
            'slot2' => $match->entry2?->display_name,
            // Entry ids (not full models) so the frontend can tell whether
            // the logged-in viewer participates in this match, without
            // leaking full TournamentEntry/User data into the bracket prop.
            'entry1Id' => $match->entry1_id,
            'entry2Id' => $match->entry2_id,
            'score1' => $match->score1,
            'score2' => $match->score2,
            'winnerEntryId' => $match->winner_entry_id,
            'status' => $match->status->value,
            'lockVersion' => $match->lock_version,
        ];
    }

    /**
     * Every match belonging to `$tournamentId`, ordered round/position, as
     * DTOs — eager-loading `entry1`/`entry2` so `slot1`/`slot2` never N+1.
     *
     * @return list<array<string, mixed>>
     */
    public static function forTournament(int $tournamentId): array
    {
        $matches = GameMatch::query()
            ->where('tournament_id', $tournamentId)
            ->with(['entry1', 'entry2'])
            ->orderBy('round')
            ->orderBy('position')
            ->get()
            ->map(fn (GameMatch $match): array => self::fromMatch($match))
            ->all();

        return array_values($matches);
    }

    /**
     * @return Collection<int, GameMatch>
     */
    public static function matchesForTournament(int $tournamentId): Collection
    {
        return GameMatch::query()
            ->where('tournament_id', $tournamentId)
            ->with(['entry1', 'entry2'])
            ->orderBy('round')
            ->orderBy('position')
            ->get();
    }
}
