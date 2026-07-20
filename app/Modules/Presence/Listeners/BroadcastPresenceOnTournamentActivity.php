<?php

declare(strict_types=1);

namespace App\Modules\Presence\Listeners;

use App\Modules\Presence\Events\PresenceUpdated;
use App\Modules\Schedule\Listeners\SyncScheduleOnTournamentSaved;
use App\Modules\Tournaments\Events\MatchCompleted;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Events\MatchWentLive;
use App\Modules\Tournaments\Events\TournamentStarted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;

/**
 * Reacts to any tournament transition that can change what the presence
 * board shows (a match becoming live/ready/completed, or a tournament
 * starting) by resolving the owning `Event` and dispatching
 * {@see PresenceUpdated} for it. Kept as a thin, synchronous dispatch
 * wrapper — mirrors {@see SyncScheduleOnTournamentSaved} —
 * since all it does is re-broadcast a bare signal.
 */
class BroadcastPresenceOnTournamentActivity
{
    public function handle(MatchReady|MatchWentLive|MatchCompleted|TournamentStarted $event): void
    {
        $eventId = match (true) {
            $event instanceof TournamentStarted => $event->tournament->event_id,
            default => $this->eventIdForMatch($event->match),
        };

        PresenceUpdated::dispatch($eventId);
    }

    private function eventIdForMatch(GameMatch $match): int
    {
        /** @var Tournament $tournament */
        $tournament = $match->tournament;

        return $tournament->event_id;
    }
}
