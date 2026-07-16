<?php

namespace App\Modules\Infoscreen\Events;

use App\Modules\Infoscreen\Actions\SetStatusSignal;
use App\Modules\Infoscreen\Enums\StatusLevel;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched to tell the public beamer screen to reload its scene list
 * (`Screen/Show.vue` listens for this and does `router.reload({ only:
 * ['scenes'] })`) rather than pushing a synthetic override scene. Used by
 * {@see SetStatusSignal}
 * when a component recovers back to {@see StatusLevel::Ok}:
 * there is nothing to show *now*, but any active status override must clear
 * on the next rotation tick rather than linger.
 *
 * Implements {@see ShouldDispatchAfterCommit} so the screen never observes a
 * reload tied to a DB write that is later rolled back, mirroring
 * {@see SceneOverride}.
 *
 * Implements {@see ShouldBroadcast} to push to the public `event.{id}`
 * channel (no auth needed — see routes/channels.php), mirroring
 * {@see SceneOverride}.
 */
class ScenesUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
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
        return 'scenes.updated';
    }
}
