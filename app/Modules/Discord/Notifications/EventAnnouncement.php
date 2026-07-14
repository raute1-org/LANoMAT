<?php

namespace App\Modules\Discord\Notifications;

use App\Modules\Discord\Channels\DiscordChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class EventAnnouncement extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $channelId,
        private readonly string $content,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [DiscordChannel::class];
    }

    /**
     * @return array{channelId: string, content: string}
     */
    public function toDiscord(object $notifiable): array
    {
        return [
            'channelId' => $this->channelId,
            'content' => $this->content,
        ];
    }
}
