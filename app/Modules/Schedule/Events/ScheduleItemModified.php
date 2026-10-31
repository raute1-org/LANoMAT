<?php

namespace App\Modules\Schedule\Events;

use App\Modules\Schedule\Listeners\AlarmScheduleItemChanged;
use App\Modules\Schedule\Models\ScheduleItem;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched once per save/delete of a `ScheduleItem` whose attendee-facing
 * attributes changed (`starts_at`, `ends_at`, `title`, `location`) or that is
 * being removed (see {@see ScheduleItem::booted()}). Consumed by the Schedule
 * module to alarm favoriters of the affected item (see
 * {@see AlarmScheduleItemChanged}). One event per save keeps several edited
 * fields bundled into a single alarm.
 *
 * For a removed item the favorites are cascade-deleted with the row, so the
 * audience is resolved up front and carried in `$audienceUserIds`.
 *
 * Implements {@see ShouldDispatchAfterCommit} so the listener never reacts
 * to a schedule-item save that is later rolled back.
 */
class ScheduleItemModified implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $fields  Changed attribute names (empty when removed).
     * @param  list<int>|null  $audienceUserIds  Pre-resolved recipients (removal only).
     */
    public function __construct(
        public readonly ScheduleItem $scheduleItem,
        public readonly array $fields = ['starts_at'],
        public readonly bool $removed = false,
        public readonly ?array $audienceUserIds = null,
    ) {}
}
