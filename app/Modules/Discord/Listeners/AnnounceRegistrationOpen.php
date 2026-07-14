<?php

namespace App\Modules\Discord\Listeners;

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Support\DiscordOutboxGuard;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Events\EventStatusChanged;

class AnnounceRegistrationOpen
{
    public function __construct(
        private readonly DiscordOutboxGuard $guard,
        private readonly DiscordClient $client,
    ) {}

    public function handle(EventStatusChanged $event): void
    {
        if ($event->to !== EventStatus::Registration) {
            return;
        }

        $channelId = config('services.discord.announce_channel_id');
        if (blank($channelId)) {
            return;
        }

        $this->guard->once(
            "event-{$event->event->id}-registration-open",
            'registration_open',
            fn () => $this->client->sendMessage(
                (string) $channelId,
                __('discord.registration_open', ['event' => $event->event->name]),
            ),
        );
    }
}
