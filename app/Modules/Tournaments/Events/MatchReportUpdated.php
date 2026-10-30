<?php

namespace App\Modules\Tournaments\Events;

use App\Modules\Tournaments\Models\GameMatch;
use App\Support\Broadcasting\RetriesBroadcast;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A participant submitted a result (match now `Reported`) or the opponent
 * disputed it (`Disputed`). Pushed to the public `tournament.{id}` channel so
 * the opponent's open page refreshes and shows the pending report. Ids and
 * status only: the score itself is never part of the (public) payload, the
 * page pulls it via the authorized Inertia reload.
 */
class MatchReportUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use RetriesBroadcast;

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
        return 'match.report_updated';
    }
}
