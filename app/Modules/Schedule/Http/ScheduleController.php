<?php

namespace App\Modules\Schedule\Http;

use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Models\ScheduleItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ScheduleController extends Controller
{
    /**
     * The public programme for the event: the full chronological item list
     * plus a "now/next" pair the widget highlights without the client having
     * to re-derive interval-overlap rules itself.
     */
    public function show(Event $event): Response
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $items = ScheduleItem::query()
            ->where('event_id', $event->id)
            ->orderBy('starts_at')
            ->orderBy('sort')
            ->get();

        $now = Carbon::now();

        return Inertia::render('Schedule/Index', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'items' => $items->map(fn (ScheduleItem $item): array => $this->itemDto($item))->all(),
            'now' => $this->currentItem($items, $now),
            'next' => $this->nextItem($items, $now),
            'labels' => trans('schedule.page'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemDto(ScheduleItem $item): array
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
     * The item whose `[starts_at, ends_at ?? starts_at+1h]` interval contains
     * `$now` — the earliest-starting one if several overlap, or null if none.
     *
     * @param  Collection<int, ScheduleItem>  $items
     * @return array<string, mixed>|null
     */
    private function currentItem(Collection $items, Carbon $now): ?array
    {
        $current = $items
            ->filter(function (ScheduleItem $item) use ($now): bool {
                $end = $item->ends_at ?? $item->starts_at->clone()->addHour();

                return $item->starts_at->lessThanOrEqualTo($now) && $end->greaterThan($now);
            })
            ->sortBy(fn (ScheduleItem $item): string => $item->starts_at->toIso8601String())
            ->first();

        return $current === null ? null : $this->itemDto($current);
    }

    /**
     * The earliest item starting strictly after `$now`, or null if none.
     *
     * @param  Collection<int, ScheduleItem>  $items
     * @return array<string, mixed>|null
     */
    private function nextItem(Collection $items, Carbon $now): ?array
    {
        $next = $items
            ->filter(fn (ScheduleItem $item): bool => $item->starts_at->greaterThan($now))
            ->sortBy(fn (ScheduleItem $item): string => $item->starts_at->toIso8601String())
            ->first();

        return $next === null ? null : $this->itemDto($next);
    }
}
