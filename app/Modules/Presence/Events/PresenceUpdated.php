<?php

declare(strict_types=1);

namespace App\Modules\Presence\Events;

use App\Modules\Infoscreen\Events\ScenesUpdated;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched whenever the presence board's underlying data changes — a
 * check-in, or a match/tournament transition — so the public presence page
 * (`Presence/Index.vue`) can partial-reload its `presence` prop rather than
 * poll. Carries no private data: the payload is empty and the authorized
 * `PresencePageController` re-fetches the projection on reload, mirroring
 * {@see ScenesUpdated}.
 *
 * Implements {@see ShouldDispatchAfterCommit} so the frontend never observes
 * a reload tied to a DB write that is later rolled back.
 *
 * Implements {@see ShouldBroadcast} to push to the public `event.{id}`
 * channel (no auth needed — see routes/channels.php), mirroring
 * {@see ScenesUpdated}.
 */
class PresenceUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $eventId,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('event.'.$this->eventId);
    }

    public function broadcastAs(): string
    {
        return 'presence.updated';
    }

    /** @return array{} */
    public function broadcastWith(): array
    {
        return [];
    }
}
