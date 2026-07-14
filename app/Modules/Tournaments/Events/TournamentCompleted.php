<?php

namespace App\Modules\Tournaments\Events;

use App\Modules\Tournaments\Actions\ConfirmMatchReport;
use App\Modules\Tournaments\Actions\OverrideMatchResult;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched once the tournament's final match is decided: the champion has
 * been written to `Tournament::$winner_entry_id` and the status transitioned
 * to `Finished`.
 *
 * Implements {@see ShouldDispatchAfterCommit} so that later listener never
 * observes pre-commit state and never fires for a subsequently rolled-back
 * transaction — dispatch is deferred until the surrounding `DB::transaction()`
 * in {@see ConfirmMatchReport}/{@see OverrideMatchResult}
 * commits.
 *
 * Implements {@see ShouldBroadcast} to push live bracket updates to the
 * public `tournament.{id}` channel (no auth needed — see routes/channels.php).
 */
class TournamentCompleted implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Tournament $tournament,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('tournament.'.$this->tournament->id);
    }

    /** @return array{tournament_id: int, status: string, winner_entry_id: int|null} */
    public function broadcastWith(): array
    {
        return [
            'tournament_id' => $this->tournament->id,
            'status' => $this->tournament->status->value,
            'winner_entry_id' => $this->tournament->winner_entry_id,
        ];
    }

    public function broadcastAs(): string
    {
        return 'tournament.completed';
    }
}
