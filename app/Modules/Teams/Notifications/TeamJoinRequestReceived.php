<?php

namespace App\Modules\Teams\Notifications;

use App\Models\User;
use App\Modules\Notifications\Support\NotificationPreferences;
use App\Modules\Teams\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Bell notification for the team owner when someone asks to join.
 */
class TeamJoinRequestReceived extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Team $team,
        public readonly User $requester,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['database'];
        }

        return app(NotificationPreferences::class)->wants($notifiable, 'teams') ? ['database'] : [];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => 'teams',
            'title' => __('teams.notifications.request_received.title'),
            'body' => __('teams.notifications.request_received.body', [
                'name' => $this->requester->name,
                'team' => $this->team->name,
            ]),
        ];
    }
}
