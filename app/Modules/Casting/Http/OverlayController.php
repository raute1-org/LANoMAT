<?php

declare(strict_types=1);

namespace App\Modules\Casting\Http;

use App\Http\Controllers\Controller;
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
}
