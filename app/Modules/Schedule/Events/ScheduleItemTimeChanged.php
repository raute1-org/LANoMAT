<?php

namespace App\Modules\Schedule\Events;

use App\Modules\Schedule\Listeners\AlarmScheduleItemChanged;
use App\Modules\Schedule\Models\ScheduleItem;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched whenever a `ScheduleItem`'s `starts_at` is created or changed
 * (see {@see ScheduleItem::booted()}). Consumed by the Schedule module to
 * alarm favoriters of the affected item (see
 * {@see AlarmScheduleItemChanged}).
 *
 * Implements {@see ShouldDispatchAfterCommit} so the listener never reacts
 * to a schedule-item save that is later rolled back.
 */
class ScheduleItemTimeChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly ScheduleItem $scheduleItem,
    ) {}
}
