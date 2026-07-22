<?php

namespace App\Modules\Discord\Events;

use App\Modules\Discord\Listeners\LogGatewayEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A member left (or was removed from) the Discord guild. Surface-only this
 * phase: dispatched onto the event bus for future listeners, logged by
 * {@see LogGatewayEvent}.
 */
class DiscordGuildMemberLeft
{
    use Dispatchable;

    public function __construct(
        public readonly string $discordUserId,
    ) {}
}
