<?php

namespace App\Modules\Discord\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched whenever Discord voice occupancy changes, so a consumer can
 * partial-reload {@see VoicePresenceProjection}. Empty payload (No-PII); the
 * public `discord-voice` channel needs no auth callback (mirrors
 * `PresenceUpdated`). Guild-wide, so not the per-event `event.{id}` channel.
 */
class DiscordVoicePresenceUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function broadcastOn(): Channel
    {
        return new Channel('discord-voice');
    }

    public function broadcastAs(): string
    {
        return 'voice.updated';
    }

    /** @return array{} */
    public function broadcastWith(): array
    {
        return [];
    }
}
