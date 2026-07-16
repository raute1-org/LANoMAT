<?php

namespace App\Modules\Seating\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Support\ScenePayload;
use App\Modules\Seating\Http\SeatingController;
use App\Modules\Seating\Models\Seat;

/**
 * The single `Seat` -> wire DTO projection shared by the public seating page
 * ({@see SeatingController}) and the infoscreen's
 * Seatmap scene ({@see ScenePayload}).
 */
class SeatProjection
{
    /**
     * @return array<string, mixed>
     */
    public static function dto(Seat $seat): array
    {
        return [
            'id' => $seat->id,
            'label' => $seat->label,
            'x' => $seat->pos_x,
            'y' => $seat->pos_y,
            'occupant' => $seat->assignment?->registration?->user?->name,
        ];
    }

    /**
     * All seats for `$event`, in grid order, as DTOs — eager-loading the
     * assignment/registration/user chain so `occupant` never N+1.
     *
     * @return list<array<string, mixed>>
     */
    public static function forEvent(Event $event): array
    {
        $seats = Seat::query()
            ->where('event_id', $event->id)
            ->with('assignment.registration.user')
            ->orderBy('pos_y')
            ->orderBy('pos_x')
            ->get()
            ->map(fn (Seat $seat): array => self::dto($seat))
            ->all();

        return array_values($seats);
    }
}
