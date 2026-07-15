<?php

namespace App\Modules\Schedule\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Models\ScheduleItem;
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

    private static function toCalendarEvent(ScheduleItem $item): CalendarEvent
    {
        $event = CalendarEvent::create($item->title)
            ->startsAt($item->starts_at)
            ->endsAt($item->ends_at ?? $item->starts_at);

        if ($item->description !== null) {
            $event->description($item->description);
        }

        if ($item->location !== null) {
            $event->address($item->location);
        }

        return $event;
    }
}
