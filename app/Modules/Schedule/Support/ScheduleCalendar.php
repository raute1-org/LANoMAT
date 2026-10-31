<?php

namespace App\Modules\Schedule\Support;

use App\Models\User;
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

    /**
     * @param  User|null  $favoritesOf  Restrict the export to this user's favorites.
     */
    public static function for(Event $event, ?User $favoritesOf = null): string
    {
        $items = ScheduleItem::query()
            ->where('event_id', $event->id)
            ->when($favoritesOf !== null, fn ($query) => $query->whereHas(
                'favorites',
                fn ($favorites) => $favorites->where('user_id', $favoritesOf?->id),
            ))
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

    public static function uid(ScheduleItem $item): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return 'schedule-item-'.$item->id.'@'.(is_string($host) && $host !== '' ? $host : 'lanomat.local');
    }

    private static function toCalendarEvent(ScheduleItem $item): CalendarEvent
    {
        // Stable identity per item so re-importing or subscribing updates the
        // existing calendar entry instead of duplicating it; SEQUENCE and
        // DTSTAMP follow the last modification so clients apply changes.
        $event = CalendarEvent::create($item->title)
            ->uniqueIdentifier(self::uid($item))
            ->createdAt($item->updated_at ?? $item->starts_at)
            ->sequence($item->updated_at?->getTimestamp() ?? 0)
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
