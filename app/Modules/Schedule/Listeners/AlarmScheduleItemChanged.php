<?php

namespace App\Modules\Schedule\Listeners;

use App\Models\User;
use App\Modules\Schedule\Contracts\ScheduleParticipantResolver;
use App\Modules\Schedule\Events\ScheduleItemTimeChanged;
use App\Modules\Schedule\Models\ScheduleItemFavorite;
use App\Modules\Schedule\Notifications\ScheduleItemChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Alarms everyone affected by a schedule item when its `starts_at` changes
 * (see {@see ScheduleItemTimeChanged}, dispatched from
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
    public function __construct(
        private readonly ScheduleParticipantResolver $participantResolver,
    ) {}

    public function handle(ScheduleItemTimeChanged $event): void
    {
        $favoriterIds = ScheduleItemFavorite::query()
            ->where('schedule_item_id', $event->scheduleItem->id)
            ->pluck('user_id');

        $favoriters = $favoriterIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $favoriterIds)->get();

        $participants = $this->participantResolver->usersFor($event->scheduleItem);

        $recipients = $favoriters->concat($participants)->unique('id');

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new ScheduleItemChanged($event->scheduleItem));
    }
}
