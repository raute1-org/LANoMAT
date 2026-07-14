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
 * Dispatched whenever a match result is recorded — via confirmed report or
 * orga override — regardless of whether that match was the tournament final.
 * Discord/voice-channel side-effects are wired in later tasks (18/21).
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
class MatchCompleted implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly GameMatch $match,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('tournament.'.$this->match->tournament_id);
    }

    /** @return array{tournament_id: int, match_id: int, status: string, winner_entry_id: int|null} */
    public function broadcastWith(): array
    {
        return [
            'tournament_id' => $this->match->tournament_id,
            'match_id' => $this->match->id,
            'status' => $this->match->status->value,
            'winner_entry_id' => $this->match->winner_entry_id,
        ];
    }

    public function broadcastAs(): string
    {
        return 'match.completed';
    }
}
