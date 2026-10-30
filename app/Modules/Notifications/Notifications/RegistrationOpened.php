<?php

namespace App\Modules\Notifications\Notifications;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Notifications\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Surfaces the M2.11 registration-open announcement in the bell — previously
 * this only reached the Discord announcement channel (the Discord module's
 * own listener remains the mirror via a direct channel-wide `sendMessage`).
 * The bell is the source of truth: this is a plain `database`-only
 * notification (no Discord DM here — the channel broadcast already covers
 * Discord for this announcement).
 */
class RegistrationOpened extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Event $event,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['database'];
        }

        return app(NotificationPreferences::class)->wants($notifiable, 'registration') ? ['database'] : [];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => 'registration',
            'title' => __('events.notifications.registration_opened.title'),
            'body' => __('events.notifications.registration_opened.body', ['event' => $this->event->name]),
        ];
    }
}
