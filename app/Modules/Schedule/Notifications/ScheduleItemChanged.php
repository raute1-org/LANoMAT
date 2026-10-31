<?php

namespace App\Modules\Schedule\Notifications;

use App\Models\User;
use App\Modules\Discord\Channels\DiscordChannel;
use App\Modules\Notifications\Support\NotificationPreferences;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Schedule\Support\ScheduleTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A favorited schedule item changed (start, end, title, location) or was removed (see
 * `AlarmScheduleItemChanged`). Bell entry always lands; the Discord DM
 * mirror is gated by the `schedule` preference (see `DiscordChannel`).
 */
class ScheduleItemChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public string $category = 'schedule';

    /**
     * @param  list<string>  $fields  Changed attribute names (see ScheduleItem::ALARM_FIELDS).
     */
    public function __construct(
        public readonly ScheduleItem $scheduleItem,
        public readonly array $fields = ['starts_at'],
        public readonly bool $removed = false,
    ) {}

    /**
     * @return array{title: string, body: string}
     */
    private function text(string $bodySuffix = ''): array
    {
        $params = [
            'title' => $this->scheduleItem->title,
            'time' => ScheduleTime::when($this->scheduleItem->starts_at),
        ];

        if ($this->removed) {
            return [
                'title' => __('schedule.notify.removed.title'),
                'body' => __('schedule.notify.removed.'.($bodySuffix ?: 'body'), $params),
            ];
        }

        if ($this->fields === ['starts_at']) {
            return [
                'title' => __('schedule.notify.changed.title'),
                'body' => __('schedule.notify.changed.'.($bodySuffix ?: 'body'), $params),
            ];
        }

        $params['what'] = collect($this->fields)
            ->map(fn (string $field): string => __('schedule.notify.field.'.$field))
            ->implode(', ');

        return [
            'title' => __('schedule.notify.modified.title'),
            'body' => __('schedule.notify.modified.'.($bodySuffix ?: 'body'), $params),
        ];
    }

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
        return ['category' => $this->category, ...$this->text()];
    }

    public function toDiscord(object $notifiable): string
    {
        return $this->text('discord')['body'];
    }
}
