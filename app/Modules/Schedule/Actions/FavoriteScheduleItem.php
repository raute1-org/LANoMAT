<?php

namespace App\Modules\Schedule\Actions;

use App\Models\User;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Schedule\Models\ScheduleItemFavorite;
use Illuminate\Database\UniqueConstraintViolationException;

class FavoriteScheduleItem
{
    /**
     * Idempotent: favoriting an already-favorited item just returns the
     * existing row. `firstOrCreate` still leaves a narrow race window
     * between the lookup and the insert under concurrent requests for the
     * same (item, user) pair — the unique index is the actual guard there,
     * so a 23505 raised by the insert is swallowed and resolved by
     * re-fetching the row the other request just created.
     */
    public function handle(ScheduleItem $item, User $user): ScheduleItemFavorite
    {
        $existing = ScheduleItemFavorite::query()
            ->where('schedule_item_id', $item->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $favorite = new ScheduleItemFavorite(['schedule_item_id' => $item->id]);
            // user_id is intentionally non-fillable (see the model) — it is
            // the ownership column and must only ever be set here, from the
            // trusted $user argument, never from client-supplied attributes.
            $favorite->forceFill(['user_id' => $user->id]);
            $favorite->save();

            return $favorite;
        } catch (UniqueConstraintViolationException) {
            // Lost a race against a concurrent favorite for the same
            // (item, user) pair — the unique index is the actual guard;
            // just re-fetch the row the other request created.
            return ScheduleItemFavorite::query()
                ->where('schedule_item_id', $item->id)
                ->where('user_id', $user->id)
                ->firstOrFail();
        }
    }
}
