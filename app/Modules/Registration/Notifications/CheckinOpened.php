<?php

namespace App\Modules\Registration\Notifications;

use App\Modules\Discord\Channels\DiscordChannel;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Actions\TriggerCheckinOpen;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifies an event's confirmed registrants that check-in has opened — the
 * "Check-in öffnet" one-click trigger (see
 * {@see TriggerCheckinOpen}). Bell is the
 * source of truth (`database` always lands); the Discord DM mirrors only
 * per the `checkin` category preference.
 */
class CheckinOpened extends Notification
{
    use Queueable;

    public readonly string $category;

    public function __construct(
        public readonly Event $event,
    ) {
        $this->category = 'checkin';
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', DiscordChannel::class];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'title' => __('registration.notifications.checkin_opened.title'),
            'body' => __('registration.notifications.checkin_opened.body', ['event' => $this->event->name]),
        ];
    }

    public function toDiscord(object $notifiable): string
    {
        return __('registration.notifications.checkin_opened.body', ['event' => $this->event->name]);
    }
}
