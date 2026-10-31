<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Events;

use App\Support\Broadcasting\RetriesBroadcast;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The set of channels changed (a match room appeared, a channel was archived). The payload is
 * empty on purpose: every client pulls its own, policy-filtered list, so nothing about who may see
 * what travels over the shared channel.
 */
class ChannelsChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use RetriesBroadcast;

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('web-voice.directory');
    }

    /** @return array<string, never> */
    public function broadcastWith(): array
    {
        return [];
    }

    public function broadcastAs(): string
    {
        return 'channels.changed';
    }
}
