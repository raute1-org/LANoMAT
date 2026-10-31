<?php

namespace App\Modules\Schedule\Listeners;

use App\Models\User;
use App\Modules\Schedule\Events\ScheduleItemModified;
use App\Modules\Schedule\Notifications\ScheduleItemChanged;
use App\Modules\Schedule\Support\ScheduleAudience;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Alarms everyone affected by a schedule item when its `starts_at` changes
 * (see {@see ScheduleItemModified}, dispatched from
 * `ScheduleItem::booted()`): every favoriter, plus — for items derived from
 * another aggregate (today: a tournament) — every affected participant, via
 * {@see ScheduleParticipantResolver} (bound to
 * `TournamentScheduleParticipantResolver` in `AppServiceProvider`). Queries
 * `ScheduleItemFavorite` directly (rather than a
 * `User::scheduleItemFavorites()` relation) to keep the cross-module
 * dependency one-directional — `Schedule` knows about `User`, not the other
 * way around. The participant resolver is the sanctioned indirection for
 * reaching `Tournaments` data: `Schedule` never queries its tables directly.
 *
 * Favoriters and participants are merged and deduplicated by user id before
 * sending, so a user who is both gets exactly one notification.
 */
class AlarmScheduleItemChanged implements ShouldQueue
{
    public function handle(ScheduleItemModified $event): void
    {
        $userIds = $event->audienceUserIds ?? ScheduleAudience::userIds($event->scheduleItem);

        if ($userIds === []) {
            return;
        }

        Notification::send(
            User::query()->whereIn('id', $userIds)->get(),
            new ScheduleItemChanged($event->scheduleItem, $event->fields, $event->removed),
        );
    }
}
