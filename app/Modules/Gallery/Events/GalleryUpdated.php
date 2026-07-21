<?php

declare(strict_types=1);

namespace App\Modules\Gallery\Events;

use App\Modules\Jukebox\Events\JukeboxUpdated;
use App\Modules\Presence\Events\PresenceUpdated;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched whenever the gallery's visible photo set changes — a photo is
 * approved or a highlight is toggled — so the participant gallery page and
 * the beamer scene (Task 7) can refresh rather than poll. Carries no private
 * data: the payload is empty and the authorized `PhotoController`/gallery
 * index re-fetch on reload, mirroring {@see JukeboxUpdated}
 * and {@see PresenceUpdated}.
 *
 * Implements {@see ShouldDispatchAfterCommit} so the frontend never observes
 * a reload tied to a DB write that is later rolled back.
 *
 * Implements {@see ShouldBroadcast} to push to the public `event.{id}`
 * channel (no auth needed — see routes/channels.php).
 */
class GalleryUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
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
        return 'gallery.updated';
    }

    /** @return array{} */
    public function broadcastWith(): array
    {
        return [];
    }
}
