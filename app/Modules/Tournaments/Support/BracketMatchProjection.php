<?php

namespace App\Modules\Tournaments\Support;

use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\EffectiveConfig;
use App\Modules\GameServers\Support\PelicanJoinLink;
use App\Modules\GameServers\Support\ResourceEstimate;
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
            'server' => self::serverDto($match, $match->serverLink),
            'warmupStartedAt' => $match->warmup_started_at?->toIso8601String(),
            'spectateHint' => self::spectateHintFor($match),
        ];
    }

    /**
     * Surfaces the game's "So schaust du zu" spectate hint (M10 T8) on the
     * match page — null (rendered as nothing, no empty placeholder) when the
     * tournament has no game or the game has no hint configured, mirroring
     * {@see ServerListProjection::installHintFor()}'s null-when-empty rule.
     * Shown regardless of ServerLink status: it's about the game, not the
     * server's lifecycle.
     *
     * @return array{gotvConnect: ?string, observerNote: ?string, replayNote: ?string}|null
     */
    private static function spectateHintFor(GameMatch $match): ?array
    {
        $game = $match->tournament?->game;

        if ($game === null || $game->spectate_hint->isEmpty()) {
            return null;
        }

        return [
            'gotvConnect' => $game->spectate_hint->gotvConnect,
            'observerNote' => $game->spectate_hint->observerNote,
            'replayNote' => $game->spectate_hint->replayNote,
        ];
    }

    /**
     * The match's game-server join info as a lean DTO, or null if no
     * ServerLink has been provisioned for it (manual mode with nothing set
     * yet, or the tournament's game has no `pelican_egg_id`). Surfaced
     * regardless of ServerLink status — Provisioning/Failed render as a
     * `LiveIndicator` state on the page rather than being hidden outright —
     * so `address`/`port`/`connectString` are only populated once the
     * GameServers module's poll-server-status job has written them (Ready).
     *
     * `estimate` (roadmap 6.7) is the pre-start RAM readout: populated only
     * while the server isn't Ready yet (once Ready, the estimate is stale
     * history, not something the viewer needs) and only when the tournament
     * has a game to estimate from — the same {@see ResourceEstimate}/
     * {@see EffectiveConfig} the enforcing {@see GuardrailPolicy} in
     * `ProvisionMatchServerJob` uses, so the UI number can never drift from
     * what's actually enforced.
     *
     * @return array{address: ?string, port: ?int, connectString: ?string, status: string, estimate: ?array{ramMb: int, maxRamMb: int, overCap: bool}}|null
     */
    private static function serverDto(GameMatch $match, ?ServerLink $link): ?array
    {
        if ($link === null) {
            return null;
        }

        $isReady = $link->status === ServerLinkStatus::Ready;

        return [
            'address' => $isReady ? $link->join_info->address : null,
            'port' => $isReady ? $link->join_info->port : null,
            'connectString' => $isReady ? PelicanJoinLink::for($link->join_info) : null,
            'status' => $link->status->value,
            'estimate' => $isReady ? null : self::estimateFor($match),
        ];
    }

    /**
     * @return array{ramMb: int, maxRamMb: int, overCap: bool}|null
     */
    private static function estimateFor(GameMatch $match): ?array
    {
        $game = $match->tournament?->game;

        if ($game === null) {
            return null;
        }

        $config = EffectiveConfig::resolve($game, presetKey: null, uploadedPath: null);
        $ramMb = ResourceEstimate::for($game, $config);
        $maxRamMb = (int) config('services.pelican.max_ram_mb');

        return [
            'ramMb' => $ramMb,
            'maxRamMb' => $maxRamMb,
            'overCap' => $ramMb > $maxRamMb,
        ];
    }

    /**
     * Every match belonging to `$tournamentId`, ordered round/position, as
     * DTOs — eager-loading `entry1`/`entry2`/`serverLink`/`tournament.game`
     * so `slot1`/`slot2`/`server` (incl. the pre-start RAM estimate) never
     * N+1.
     *
     * @return list<array<string, mixed>>
     */
    public static function forTournament(int $tournamentId): array
    {
        $matches = GameMatch::query()
            ->where('tournament_id', $tournamentId)
            ->with(['entry1', 'entry2', 'serverLink', 'tournament.game'])
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
            ->with(['entry1', 'entry2', 'serverLink', 'tournament.game'])
            ->orderBy('round')
            ->orderBy('position')
            ->get();
    }
}
