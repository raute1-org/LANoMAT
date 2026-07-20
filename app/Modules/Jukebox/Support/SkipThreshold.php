<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;

/**
 * Community skip-vote threshold for an event's currently playing track:
 * `max(3, ceil(checkedInCount * skip_ratio))`. The floor of 3 keeps small
 * crowds from being able to skip on a single vote; `skip_ratio` is
 * configurable via `config('jukebox.skip_ratio')` (default 0.5).
 */
class SkipThreshold
{
    public static function for(Event $event): int
    {
        $checkedInCount = EventRegistration::query()
            ->where('event_id', $event->id)
            ->whereNotNull('checked_in_at')
            ->count();

        $ratio = (float) config('jukebox.skip_ratio', 0.5);

        return max(3, (int) ceil($checkedInCount * $ratio));
    }
}
