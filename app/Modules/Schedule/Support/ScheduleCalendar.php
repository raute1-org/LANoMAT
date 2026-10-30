<?php

namespace App\Modules\Schedule\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Enums\ScheduleItemType;
use App\Modules\Schedule\Models\ScheduleItem;
use Carbon\CarbonInterface;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event as CalendarEvent;

/**
 * Builds the downloadable ICS export of an event's public programme.
 *
 * Item ordering mirrors `ScheduleController::show()` (chronological, then
 * `sort`) so the calendar and the page always agree on the schedule.
 */
class ScheduleCalendar
{
    private const TOURNAMENT_HOURS = 2;

    private const DEFAULT_HOURS = 1;

    public static function for(Event $event): string
    {
        $items = ScheduleItem::query()
            ->where('event_id', $event->id)
            ->orderBy('starts_at')
            ->orderBy('sort')
            ->get();

        $calendar = Calendar::create($event->name);

        foreach ($items as $item) {
            $calendar->event(self::toCalendarEvent($item));
        }

        return $calendar->get();
    }

    /**
     * Items without an explicit end (tournaments never carry one) would
     * export as zero-length events that calendar apps hide or collapse, so
     * they get an estimated duration: 2 h for a tournament, 1 h otherwise.
     */
    private static function estimatedEnd(ScheduleItem $item): CarbonInterface
    {
        $hours = $item->type === ScheduleItemType::Tournament ? self::TOURNAMENT_HOURS : self::DEFAULT_HOURS;

        return $item->starts_at->copy()->addHours($hours);
    }

    private static function toCalendarEvent(ScheduleItem $item): CalendarEvent
    {
        $event = CalendarEvent::create($item->title)
            ->startsAt($item->starts_at)
            ->endsAt($item->ends_at ?? self::estimatedEnd($item));

        if ($item->description !== null) {
            $event->description($item->description);
        }

        if ($item->location !== null) {
            $event->address($item->location);
        }

        return $event;
    }
}
