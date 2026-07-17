<?php

namespace App\Modules\Infoscreen\Listeners;

use App\Modules\GameServers\Events\MatchScoreUpdated;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Events\SceneOverride;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacts to a live CS2 round/score update ({@see MatchScoreUpdated}, roadmap
 * 6.9) by broadcasting a synthetic {@see SceneType::Scoreboard} scene via
 * {@see SceneOverride} onto the tournament's *event* channel (`event.{id}`)
 * — the beamer live-scoreboard moment, mirroring
 * {@see BroadcastWinnerMoment}/{@see GongOnMatchLive}'s bridge from the
 * narrower `tournament.{id}` channel `MatchScoreUpdated` itself rides onto
 * the wider audience the beamer screen actually subscribes to.
 *
 * Unlike the winner/gong moments, a live scoreboard has no natural "done"
 * point mid-match — it stays interrupting the rotation for a short window
 * per update and simply gets re-triggered by the next round's score update,
 * which keeps it current without persisting any "currently showing"
 * state of its own.
 *
 * Deliberately not idempotent, same rationale as
 * {@see BroadcastWinnerMoment}/{@see GongOnMatchLive}: a duplicate/replayed
 * `MatchScoreUpdated` would just re-show the same score, which is harmless
 * for a beamer display.
 */
class BroadcastScoreboardOnScoreUpdated implements ShouldQueue
{
    private const DURATION_SEC = 10;

    public function handle(MatchScoreUpdated $event): void
    {
        $match = $event->match->fresh(['tournament']);

        if ($match === null) {
            return;
        }

        $tournament = $match->tournament;

        if ($tournament === null) {
            return;
        }

        SceneOverride::dispatch($tournament->event_id, [
            'type' => SceneType::Scoreboard->value,
            'durationSec' => self::DURATION_SEC,
            'config' => [],
            'data' => [
                'tournament' => $tournament->name,
                'team1' => $event->team1,
                'team2' => $event->team2,
                'score1' => $event->score1,
                'score2' => $event->score2,
                'round' => $event->round,
            ],
        ]);
    }
}
