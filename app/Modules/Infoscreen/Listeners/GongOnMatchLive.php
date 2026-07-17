<?php

namespace App\Modules\Infoscreen\Listeners;

use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Tournaments\Events\MatchWentLive;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacts to a match's warmup->live "Go" trigger ({@see MatchWentLive}) by
 * broadcasting a synthetic {@see SceneType::Gong} scene via
 * {@see SceneOverride} onto the tournament's *event* channel
 * (`event.{id}`) — the beamer "gong" moment, mirroring
 * {@see BroadcastWinnerMoment}'s bridge from the narrower
 * `tournament.{id}` channel `MatchWentLive` itself rides onto the wider
 * audience the beamer screen actually subscribes to.
 *
 * Deliberately not idempotent, same rationale as
 * {@see BroadcastWinnerMoment}: a re-fired `MatchWentLive` would re-show the
 * gong overlay, which is harmless for a beamer display and not worth
 * persisting dedup state for.
 */
class GongOnMatchLive implements ShouldQueue
{
    private const DURATION_SEC = 6;

    public function handle(MatchWentLive $event): void
    {
        $match = $event->match->fresh(['entry1', 'entry2', 'tournament']);

        if ($match === null) {
            return;
        }

        $tournament = $match->tournament;

        if ($tournament === null) {
            return;
        }

        SceneOverride::dispatch($tournament->event_id, [
            'type' => SceneType::Gong->value,
            'durationSec' => self::DURATION_SEC,
            'config' => [],
            'data' => [
                'tournament' => $tournament->name,
                'slot1' => $match->entry1?->display_name,
                'slot2' => $match->entry2?->display_name,
            ],
        ]);
    }
}
