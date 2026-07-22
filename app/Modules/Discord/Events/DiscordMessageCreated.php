<?php

namespace App\Modules\Discord\Events;

use App\Modules\Discord\Listeners\LogGatewayEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was posted in a guild channel. Surface-only this phase:
 * dispatched onto the event bus for future listeners, logged by
 * {@see LogGatewayEvent}.
 */
class DiscordMessageCreated
{
    use Dispatchable;

    public function __construct(
        public readonly string $channelId,
        public readonly string $authorId,
        public readonly string $messageId,
    ) {}
}
