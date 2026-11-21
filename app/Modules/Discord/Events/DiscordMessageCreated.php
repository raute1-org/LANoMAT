<?php

namespace App\Modules\Discord\Events;

use App\Modules\Discord\Listeners\LogGatewayEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was posted in a guild channel or DM. Surface-only this phase:
 * dispatched onto the event bus for future listeners, logged by
 * {@see LogGatewayEvent}.
 *
 * `content` is null for ordinary chatter: the gateway enables Discord's
 * privileged Message Content intent but only forwards the body for messages
 * addressed to the guest assistant (mention, DM, `!ask`/`!game`). The same
 * applies to `context` (author/channel/guild).
 */
class DiscordMessageCreated
{
    use Dispatchable;

    /**
     * @param  array<int, array{id: string, username: string}>  $mentions
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $channelId,
        public readonly string $authorId,
        public readonly string $messageId,
        public readonly ?string $content = null,
        public readonly bool $isDM = false,
        public readonly array $mentions = [],
        public readonly array $context = [],
    ) {}
}
