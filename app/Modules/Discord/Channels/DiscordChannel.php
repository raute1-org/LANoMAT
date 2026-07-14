<?php

namespace App\Modules\Discord\Channels;

use App\Models\User;
use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Notifications\Support\NotificationPreferences;
use Illuminate\Notifications\Notification;

/**
 * Delivers notifications as a per-user Discord DM.
 *
 * This is distinct from the channel-wide broadcasts used by
 * AnnounceRegistrationOpen and SendRemindersCommand, which call
 * DiscordClient::sendMessage() directly against the configured announcement
 * channel — that is the right design for "everyone should see this" content
 * and does not go through the notification system. This channel exists for
 * the opposite case: a message addressed to one specific user, e.g. a match
 * reminder or LFG ping (see DiscordDirectMessage).
 */
class DiscordChannel
{
    public function __construct(
        private readonly DiscordClient $client,
        private readonly NotificationPreferences $preferences,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toDiscord')) {
            return;
        }

        if (! $notifiable instanceof User || blank($notifiable->discord_id)) {
            return;
        }

        $category = $notification->category ?? 'discord';
        if (! $this->preferences->wants($notifiable, $category)) {
            return;
        }

        $content = $notification->toDiscord($notifiable);

        $this->client->sendDm($notifiable->discord_id, $content);
    }
}
