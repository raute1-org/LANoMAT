<?php

namespace App\Modules\Schedule\Listeners;

use App\Models\User;
use App\Modules\Schedule\Events\ScheduleItemTimeChanged;
use App\Modules\Schedule\Models\ScheduleItemFavorite;
use App\Modules\Schedule\Notifications\ScheduleItemChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Alarms every favoriter of a schedule item when its `starts_at` changes
 * (see {@see ScheduleItemTimeChanged}, dispatched from
 * `ScheduleItem::booted()`). Queries `ScheduleItemFavorite` directly (rather
 * than a `User::scheduleItemFavorites()` relation) to keep the cross-module
 * dependency one-directional — `Schedule` knows about `User`, not the other
 * way around.
 */
class AlarmScheduleItemChanged implements ShouldQueue
{
    public function handle(ScheduleItemTimeChanged $event): void
    {
        $userIds = ScheduleItemFavorite::query()
            ->where('schedule_item_id', $event->scheduleItem->id)
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return;
        }

        $users = User::query()->whereIn('id', $userIds)->get();

        Notification::send($users, new ScheduleItemChanged($event->scheduleItem));
    }
}
