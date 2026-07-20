<?php

declare(strict_types=1);

namespace App\Modules\Casting\Http;

use App\Http\Controllers\Controller;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Support\BracketMatchProjection;
use Inertia\Inertia;
use Inertia\Response;

class OverlayController extends Controller
{
    /**
     * A public, no-auth, transparent-background bracket overlay for OBS
     * browser sources: renders `BracketView.vue` render-only (no report/Go
     * controls — an anonymous browser source cannot act) and live-reloads
     * over the same public `tournament.{id}` channel the participant
     * tournament page uses (see `useTournamentChannel`).
     *
     * Gated by the owning event's public visibility exactly like the beamer
     * screen (`ScreenController::show`) and the tournament show page
     * (`TournamentPageController::show`) — an overlay is not a new
     * visibility boundary, only a new *rendering* of already-public bracket
     * data, so it may never expose more than those surfaces already do.
     */
    public function bracket(Tournament $tournament): Response
    {
        $tournament->load('event');
        $event = $tournament->event;
        abort_if($event === null, 500, 'Tournament has no associated event.');
        abort_unless($event->isPubliclyVisible(), 404);

        return Inertia::render('Overlay/Bracket', [
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
            ],
            'matches' => BracketMatchProjection::forTournament($tournament->id),
            'labels' => [
                'matchStatusLabels' => trans('tournaments.match_status'),
                'reportLabels' => trans('tournaments.report'),
                'bracketLabels' => trans('tournaments.page'),
                'liveScoreLabels' => trans('gameservers.live_score'),
            ],
        ]);
    }

    /**
     * A public, no-auth, transparent-background per-match scoreboard overlay
     * for OBS browser sources: seeds an initial `data` snapshot from the
     * match's persisted state (`entry1`/`entry2` display names, `score1`/
     * `score2`) and then updates live over the public `tournament.{id}`
     * channel's `.match.score_updated` broadcast — see
     * `useMatchScore.ts`/`Overlay/Scoreboard.vue`. `round` is deliberately
     * omitted here: it is not persisted on {@see GameMatch}, only carried in
     * the live event payload (mirrors
     * `BroadcastScoreboardOnScoreUpdated`, which feeds the beamer's
     * `SceneScoreboard` the same way).
     *
     * Gated by the owning event's public visibility, same rule as
     * {@see self::bracket()} — an overlay is not a new visibility boundary,
     * only a new rendering of already-public live-score data.
     */
    public function scoreboard(GameMatch $match): Response
    {
        $match->load(['tournament.event', 'entry1', 'entry2']);
        $tournament = $match->tournament;
        abort_if($tournament === null, 500, 'Match has no associated tournament.');
        $event = $tournament->event;
        abort_if($event === null, 500, 'Tournament has no associated event.');
        abort_unless($event->isPubliclyVisible(), 404);

        return Inertia::render('Overlay/Scoreboard', [
            'tournamentId' => $tournament->id,
            'matchId' => $match->id,
            'data' => [
                'tournament' => $tournament->name,
                'team1' => $match->entry1?->display_name,
                'team2' => $match->entry2?->display_name,
                'score1' => $match->score1,
                'score2' => $match->score2,
            ],
            'labels' => trans('overlay.scoreboard'),
        ]);
    }
}
