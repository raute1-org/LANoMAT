<?php

namespace App\Modules\Schedule\Notifications;

use App\Models\User;
use App\Modules\Discord\Channels\DiscordChannel;
use App\Modules\Notifications\Support\NotificationPreferences;
use App\Modules\Schedule\Models\ScheduleItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A favorited schedule item is starting soon (see
 * `SendScheduleRemindersCommand`). Bell entry always lands; the Discord DM
 * mirror is gated by the `schedule` preference (see `DiscordChannel`).
 */
class ScheduleItemStartingSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public string $category = 'schedule';

    public function __construct(
        public readonly ScheduleItem $scheduleItem,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['database'];
        }

        return app(NotificationPreferences::class)->wants($notifiable, $this->category)
            ? ['database', DiscordChannel::class]
            : ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'title' => __('schedule.notify.starting_soon.title'),
            'body' => __('schedule.notify.starting_soon.body', [
                'title' => $this->scheduleItem->title,
                'time' => $this->scheduleItem->starts_at->format('H:i'),
            ]),
        ];
    }

    public function toDiscord(object $notifiable): string
    {
        return __('schedule.notify.starting_soon.discord', [
            'title' => $this->scheduleItem->title,
            'time' => $this->scheduleItem->starts_at->format('H:i'),
        ]);
    }
}
