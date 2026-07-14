<?php

namespace App\Modules\Events\Support;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;

class CurrentEvent
{
    /**
     * @var array<int, EventStatus>
     */
    private const PUBLIC_STATUSES = [
        EventStatus::Announced,
        EventStatus::Registration,
        EventStatus::Live,
    ];

    public function get(): ?Event
    {
        /** @var array<string, int> */
        $rank = [
            EventStatus::Live->value => 3,
            EventStatus::Registration->value => 2,
            EventStatus::Announced->value => 1,
        ];

        return Event::query()
            ->whereIn('status', array_map(fn (EventStatus $s) => $s->value, self::PUBLIC_STATUSES))
            ->get()
            ->sortByDesc(fn (Event $e) => [$rank[$e->status->value], $e->starts_at?->getTimestamp() ?? 0])
            ->first();
    }
}
