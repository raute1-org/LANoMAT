<?php

namespace App\Modules\Discord\Events;

use App\Modules\Discord\Listeners\LogGatewayEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reaction was added/removed on a guild message. Surface-only this phase:
 * dispatched onto the event bus for future listeners (e.g. reaction-to-
 * register), logged by {@see LogGatewayEvent}.
 */
class DiscordMessageReactionChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $messageId,
        public readonly string $channelId,
        public readonly string $userId,
        public readonly string $emoji,
        public readonly bool $added,
    ) {}
}
