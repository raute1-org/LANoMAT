<?php

namespace App\Modules\Schedule\Support;

use App\Support\DisplayTimezone;
use DateTimeInterface;

/**
 * Human-readable start time for schedule notifications, in the display
 * timezone: just "21:00" for today, otherwise weekday and date as well
 * ("Sa., 01.11., 21:00") so a move to another day never reads like a no-op.
 */
class ScheduleTime
{
    public static function when(DateTimeInterface $instant): string
    {
        // `locale()` is a getter/setter overload; with an argument it returns the instance.
        $local = DisplayTimezone::local($instant)->locale('de');

        if (is_string($local)) {
            return $instant->format('d.m.Y H:i');
        }

        return $local->isSameDay(DisplayTimezone::local(now()))
            ? $local->format('H:i')
            : $local->translatedFormat('D, d.m., H:i');
    }
}
