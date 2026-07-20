<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Events;

use App\Modules\Infoscreen\Events\ScenesUpdated;
use App\Modules\Jukebox\Support\JukeboxQueue;
use App\Modules\Presence\Events\PresenceUpdated;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched whenever the jukebox tick reconciles LANoMAT's queue state
 * against Music Assistant (a track finished, the next one started playing)
 * so the participant queue view can partial-reload rather than poll.
 * Carries no private data: the payload is empty and the authorized queue
 * controller re-fetches {@see JukeboxQueue} on
 * reload, mirroring {@see PresenceUpdated} and
 * {@see ScenesUpdated}.
 *
 * Implements {@see ShouldDispatchAfterCommit} so the frontend never observes
 * a reload tied to a DB write that is later rolled back.
 *
 * Implements {@see ShouldBroadcast} to push to the public `event.{id}`
 * channel (no auth needed — see routes/channels.php).
 */
class JukeboxUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $eventId,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel("event.{$this->eventId}");
    }

    public function broadcastAs(): string
    {
        return 'jukebox.updated';
    }

    /** @return array{} */
    public function broadcastWith(): array
    {
        return [];
    }
}
