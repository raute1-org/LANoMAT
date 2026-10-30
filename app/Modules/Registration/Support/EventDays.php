<?php

namespace App\Modules\Registration\Support;

use App\Modules\Events\Models\Event;
use Carbon\CarbonImmutable;

/**
 * The calendar days an event spans (app timezone), starts_at..ends_at inclusive.
 * Single source for validation, form options and the arrival forecast.
 *
 * An ends_at at exactly 00:00 does not add an extra (empty) day. A missing
 * starts_at yields no days; a missing ends_at yields the start day only.
 */
class EventDays
{
    /** @return list<CarbonImmutable> start-of-day instances */
    public static function between(?\DateTimeInterface $startsAt, ?\DateTimeInterface $endsAt): array
    {
        if ($startsAt === null) {
            return [];
        }

        $tz = (string) config('app.timezone');
        $first = CarbonImmutable::instance($startsAt)->setTimezone($tz)->startOfDay();
        $last = $first;

        if ($endsAt !== null) {
            $end = CarbonImmutable::instance($endsAt)->setTimezone($tz);
            // Ending exactly at midnight does not open another day.
            $candidate = $end->equalTo($end->startOfDay()) ? $end->subSecond()->startOfDay() : $end->startOfDay();
            if ($candidate->greaterThan($first)) {
                $last = $candidate;
            }
        }

        $days = [];
        for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $days[] = $day;
        }

        return $days;
    }

    /** @return list<CarbonImmutable> */
    public static function for(Event $event): array
    {
        return self::between($event->starts_at, $event->ends_at);
    }

    /** @return list<string> Y-m-d */
    public static function dates(Event $event): array
    {
        return array_map(fn (CarbonImmutable $d): string => $d->toDateString(), self::for($event));
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(Event $event): array
    {
        return array_map(fn (CarbonImmutable $d): array => [
            'value' => $d->toDateString(),
            'label' => self::label($d),
        ], self::for($event));
    }

    /** "Fr, 20.11." */
    public static function label(CarbonImmutable $day): string
    {
        return self::weekday($day).', '.$day->format('d.m.');
    }

    /** "Fr" */
    public static function weekday(CarbonImmutable $day): string
    {
        return __('registration.arrival.weekday.'.$day->dayOfWeekIso);
    }
}
