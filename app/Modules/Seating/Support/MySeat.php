<?php

namespace App\Modules\Seating\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Seating\Models\Seat;

/**
 * Read model: the seat the given user currently holds at an event, if any.
 */
class MySeat
{
    /**
     * @return array{id: int, label: string}|null
     */
    public static function for(Event $event, User $user): ?array
    {
        $seat = Seat::query()
            ->where('event_id', $event->id)
            ->whereHas('assignment.registration', fn ($q) => $q->where('user_id', $user->id))
            ->first();

        return $seat === null ? null : ['id' => $seat->id, 'label' => $seat->label];
    }
}
