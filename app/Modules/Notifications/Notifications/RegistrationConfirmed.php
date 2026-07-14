<?php

namespace App\Modules\Notifications\Notifications;

use App\Models\User;
use App\Modules\Notifications\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RegistrationConfirmed extends Notification
{
    use Queueable;

    public function __construct(public readonly string $eventName) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['database'];
        }

        return app(NotificationPreferences::class)->wants($notifiable, 'registration')
            ? ['database']
            : [];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => 'registration',
            'title' => __('notifications.registration_confirmed.title'),
            'body' => __('notifications.registration_confirmed.body', ['event' => $this->eventName]),
        ];
    }
}
