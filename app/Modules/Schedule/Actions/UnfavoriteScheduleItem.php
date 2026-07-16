<?php

namespace App\Modules\Schedule\Actions;

use App\Models\User;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Schedule\Models\ScheduleItemFavorite;

class UnfavoriteScheduleItem
{
    /**
     * Idempotent: unfavoriting an item that was never favorited (or already
     * unfavorited) is a no-op rather than an error.
     */
    public function handle(ScheduleItem $item, User $user): void
    {
        ScheduleItemFavorite::query()
            ->where('schedule_item_id', $item->id)
            ->where('user_id', $user->id)
            ->delete();
    }
}
