<?php

namespace App\Modules\Tournaments\Events;

use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched once a tournament transitions to `Live`: matches are persisted
 * and the bracket is playable. Discord/voice-channel side-effects are wired
 * in later tasks (18/21).
 *
 * Implements {@see ShouldDispatchAfterCommit} so listeners never observe
 * pre-commit state and never fire for a subsequently rolled-back
 * transaction — dispatch is deferred until the surrounding `DB::transaction()`
 * in {@see StartTournament} commits.
 *
 * Implements {@see ShouldBroadcast} to push live bracket updates to the
 * public `tournament.{id}` channel (no auth needed — see routes/channels.php).
 */
class TournamentStarted implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Tournament $tournament,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('tournament.'.$this->tournament->id);
    }

    /** @return array{tournament_id: int, status: string} */
    public function broadcastWith(): array
    {
        return [
            'tournament_id' => $this->tournament->id,
            'status' => $this->tournament->status->value,
        ];
    }

    public function broadcastAs(): string
    {
        return 'tournament.started';
    }
}
