<?php

namespace App\Modules\Infoscreen\Events;

use App\Modules\Voting\Events\PollUpdated;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched to push a single scene onto the public beamer screen right
 * now, interrupting its rotation for the scene's duration before it resumes
 * (e.g. a tournament winner reveal, an orga "show now" action, a trigger).
 * Tasks 7/8/10/12 dispatch this; this task only defines it and proves the
 * loop with the Filament/manual "show now" case.
 *
 * Implements {@see ShouldDispatchAfterCommit} so the screen never observes
 * an override tied to a DB write that is later rolled back.
 *
 * Implements {@see ShouldBroadcast} to push to the public `event.{id}`
 * channel (no auth needed — see routes/channels.php), mirroring
 * {@see PollUpdated}.
 */
class SceneOverride implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array{id?: int, type: string, durationSec: int, config: array<string, mixed>, data: array<string, mixed>}  $scene
     */
    public function __construct(
        public readonly int $eventId,
        public readonly array $scene,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('event.'.$this->eventId);
    }

    /**
     * @return array{scene: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return ['scene' => $this->scene];
    }

    public function broadcastAs(): string
    {
        return 'scene.override';
    }
}
