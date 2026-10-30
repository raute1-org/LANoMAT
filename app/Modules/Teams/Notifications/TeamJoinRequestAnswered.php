<?php

namespace App\Modules\Teams\Notifications;

use App\Models\User;
use App\Modules\Notifications\Support\NotificationPreferences;
use App\Modules\Teams\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Bell notification for the requester once the team owner accepted or
 * declined their join request.
 */
class TeamJoinRequestAnswered extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Team $team,
        public readonly bool $accepted,
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
        $key = $this->accepted ? 'request_accepted' : 'request_declined';

        return [
            'category' => 'teams',
            'title' => __("teams.notifications.{$key}.title"),
            'body' => __("teams.notifications.{$key}.body", ['team' => $this->team->name]),
        ];
    }
}
