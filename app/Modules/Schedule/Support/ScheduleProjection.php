<?php

namespace App\Modules\Schedule\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Support\ScenePayload;
use App\Modules\Schedule\Http\ScheduleController;
use App\Modules\Schedule\Models\ScheduleItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The single `ScheduleItem` -> wire DTO projection plus the "now/next"
 * derivation, shared by the public programme page
 * ({@see ScheduleController}) and the
 * infoscreen's Schedule scene
 * ({@see ScenePayload}).
 */
class ScheduleProjection
{
    /**
     * @return array<string, mixed>
     */
    public static function itemDto(ScheduleItem $item): array
    {
        return [
            'id' => $item->id,
            'type' => $item->type->value,
            'typeLabel' => $item->type->label(),
            'title' => $item->title,
            'description' => $item->description,
            'startsAt' => $item->starts_at->toIso8601String(),
            'endsAt' => $item->ends_at?->toIso8601String(),
            'location' => $item->location,
        ];
    }

    /**
     * @return Collection<int, ScheduleItem>
     */
    public static function itemsFor(Event $event): Collection
    {
        return ScheduleItem::query()
            ->where('event_id', $event->id)
            ->orderBy('starts_at')
            ->orderBy('sort')
            ->get();
    }

    /**
     * @param  Collection<int, ScheduleItem>  $items
     * @return list<array<string, mixed>>
     */
    public static function itemDtos(Collection $items): array
    {
        return array_values($items->map(fn (ScheduleItem $item): array => self::itemDto($item))->all());
    }

    /**
     * The item whose `[starts_at, ends_at ?? starts_at+1h]` interval contains
     * `$now` — the earliest-starting one if several overlap, or null if none.
     *
     * @param  Collection<int, ScheduleItem>  $items
     * @return array<string, mixed>|null
     */
    public static function currentItem(Collection $items, Carbon $now): ?array
    {
        $current = $items
            ->filter(function (ScheduleItem $item) use ($now): bool {
                $end = $item->ends_at ?? $item->starts_at->clone()->addHour();

                return $item->starts_at->lessThanOrEqualTo($now) && $end->greaterThan($now);
            })
            ->sortBy(fn (ScheduleItem $item): string => $item->starts_at->toIso8601String())
            ->first();

        return $current === null ? null : self::itemDto($current);
    }

    /**
     * The earliest item starting strictly after `$now`, or null if none.
     *
     * @param  Collection<int, ScheduleItem>  $items
     * @return array<string, mixed>|null
     */
    public static function nextItem(Collection $items, Carbon $now): ?array
    {
        $next = $items
            ->filter(fn (ScheduleItem $item): bool => $item->starts_at->greaterThan($now))
            ->sortBy(fn (ScheduleItem $item): string => $item->starts_at->toIso8601String())
            ->first();

        return $next === null ? null : self::itemDto($next);
    }
}
