<?php

namespace App\Modules\Tournaments\Events;

use App\Modules\Tournaments\Actions\GoLive;
use App\Modules\Tournaments\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched by {@see GoLive} the moment a `Warmup` match is flipped back to
 * `Ready` (live-ish) by an orga/helper's "Go" action — the beamer's "gong"
 * moment. `App\Modules\Infoscreen\Listeners\GongOnMatchLive` reacts to this
 * to push a synthetic `gong` scene onto the event's beamer via
 * `SceneOverride`.
 *
 * Implements {@see ShouldDispatchAfterCommit} so listeners never observe an
 * event tied to a rolled-back transaction — mirrors {@see MatchReady}/
 * {@see MatchCompleted}.
 *
 * Implements {@see ShouldBroadcast} to push a live bracket update to the
 * public `tournament.{id}` channel, mirroring {@see MatchReady}.
 */
class MatchWentLive implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly GameMatch $match,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('tournament.'.$this->match->tournament_id);
    }

    /** @return array{tournament_id: int, match_id: int, status: string} */
    public function broadcastWith(): array
    {
        return [
            'tournament_id' => $this->match->tournament_id,
            'match_id' => $this->match->id,
            'status' => $this->match->status->value,
        ];
    }

    public function broadcastAs(): string
    {
        return 'match.went_live';
    }
}
