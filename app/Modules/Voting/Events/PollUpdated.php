<?php

namespace App\Modules\Voting\Events;

use App\Modules\Voting\Actions\CastVote;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Support\PollResults;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched whenever a poll's tallies change (a vote is cast). Carries the
 * full {@see PollResults} projection so the live results UI never needs a
 * follow-up fetch.
 *
 * Implements {@see ShouldDispatchAfterCommit} so listeners/broadcast never
 * observe a vote that is later rolled back — dispatch is deferred until the
 * surrounding `DB::transaction()` in {@see CastVote}
 * commits.
 *
 * Implements {@see ShouldBroadcast} to push live results to the public
 * `event.{id}` channel (no auth needed — see routes/channels.php).
 */
class PollUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Poll $poll,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('event.'.$this->poll->event_id);
    }

    /**
     * @return array{
     *     pollId: int,
     *     question: string,
     *     totalVotes: int,
     *     options: array<int, array{id: int, label: string, count: int, percent: float}>,
     * }
     */
    public function broadcastWith(): array
    {
        return PollResults::for($this->poll);
    }

    public function broadcastAs(): string
    {
        return 'poll.updated';
    }
}
