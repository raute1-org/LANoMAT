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
        $local = DisplayTimezone::local($instant)->locale('de');

        return $local->isSameDay(DisplayTimezone::local(now()))
            ? $local->format('H:i')
            : $local->translatedFormat('D, d.m., H:i');
    }
}
