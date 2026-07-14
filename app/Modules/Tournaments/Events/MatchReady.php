<?php

namespace App\Modules\Tournaments\Events;

use App\Modules\Tournaments\Actions\ConfirmMatchReport;
use App\Modules\Tournaments\Actions\OverrideMatchResult;
use App\Modules\Tournaments\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a match transitions to `Ready` because both of its slots
 * now hold a real entrant — either because a report was just confirmed and
 * this is a freshly-fed downstream match, or an orga override had the same
 * effect. Discord/voice-channel side-effects are wired in later tasks (18/21).
 *
 * Implements {@see ShouldDispatchAfterCommit} so those later listeners never
 * observe pre-commit state and never fire for a subsequently rolled-back
 * transaction — dispatch is deferred until the surrounding `DB::transaction()`
 * in {@see ConfirmMatchReport}/{@see OverrideMatchResult}
 * commits.
 *
 * Implements {@see ShouldBroadcast} to push live bracket updates to the
 * public `tournament.{id}` channel (no auth needed — see routes/channels.php).
 */
class MatchReady implements ShouldBroadcast, ShouldDispatchAfterCommit
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
        return 'match.ready';
    }
}
