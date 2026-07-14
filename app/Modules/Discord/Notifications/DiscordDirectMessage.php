<?php

namespace App\Modules\Discord\Notifications;

use App\Modules\Discord\Channels\DiscordChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A generic per-user Discord DM notification. Consumed by the DiscordChannel,
 * which resolves the notifiable's discord_id and respects notification
 * preferences for the given category.
 *
 * This is the pattern M3 features should build on for per-user Discord
 * notifications (e.g. match reminders, LFG pings) — construct one of these
 * (or a small subclass) rather than calling DiscordClient::sendDm() directly,
 * so preference-suppression and missing-discord_id handling stay centralized.
 */
class DiscordDirectMessage extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $content,
        public readonly string $category = 'discord',
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [DiscordChannel::class];
    }

    public function toDiscord(object $notifiable): string
    {
        return $this->content;
    }
}
