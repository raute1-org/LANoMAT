<?php

namespace App\Modules\Discord\Channels;

use App\Modules\Discord\Contracts\DiscordClient;
use Illuminate\Notifications\Notification;

class DiscordChannel
{
    public function __construct(private readonly DiscordClient $client) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toDiscord')) {
            return;
        }

        /** @var array{channelId: string, content: string} $payload */
        $payload = $notification->toDiscord($notifiable);

        $this->client->sendMessage($payload['channelId'], $payload['content']);
    }
}
