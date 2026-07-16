<?php

namespace App\Modules\Schedule\Console;

use App\Modules\Schedule\Models\ScheduleItemFavorite;
use App\Modules\Schedule\Notifications\ScheduleItemStartingSoon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendScheduleRemindersCommand extends Command
{
    protected $signature = 'lanomat:send-schedule-reminders';

    protected $description = 'Send a start reminder to favoriters of schedule items starting within the lead window, deduplicated via reminded_at.';

    /**
     * How far ahead of an item's start we notify. Runs `everyMinute()` (see
     * routes/console.php), so a single 15-minute lead window is enough to
     * guarantee every eligible favorite gets exactly one reminder.
     */
    private const LEAD_MINUTES = 15;

    public function handle(): int
    {
        $now = now();

        $favorites = ScheduleItemFavorite::query()
            ->whereNull('reminded_at')
            ->whereHas('scheduleItem', function ($query) use ($now): void {
                $query->where('starts_at', '>', $now)
                    ->where('starts_at', '<=', $now->clone()->addMinutes(self::LEAD_MINUTES));
            })
            ->with(['scheduleItem', 'user'])
            ->get();

        foreach ($favorites as $favorite) {
            $scheduleItem = $favorite->scheduleItem;
            $user = $favorite->user;

            // Guarded by the foreign key constraints on schedule_item_favorites
            // (see the migration) — neither relation can actually be missing
            // for a persisted row; this narrows the type for static analysis.
            if ($scheduleItem === null || $user === null) {
                continue;
            }

            Notification::send($user, new ScheduleItemStartingSoon($scheduleItem));

            $favorite->forceFill(['reminded_at' => $now])->save();
        }

        return self::SUCCESS;
    }
}
