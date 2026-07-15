<?php

namespace App\Modules\Discord\Interactions\Commands;

use App\Modules\Discord\Interactions\InteractionResponse;
use App\Modules\Events\Support\CurrentEvent;
use App\Modules\Schedule\Http\ScheduleController;
use App\Modules\Schedule\Models\ScheduleItem;
use Illuminate\Support\Carbon;

/**
 * `/schedule` — no subcommand. Lists the next few upcoming
 * {@see ScheduleItem}s for the current event, resolved the same way
 * {@see ScheduleController} does for the public programme page:
 * {@see CurrentEvent} already only ever resolves an event in a
 * publicly-visible status (Announced/Registration/Live), so no additional
 * `isPubliclyVisible()` check is needed here.
 */
class ScheduleCommand
{
    /**
     * How many upcoming items to surface — enough to be useful without
     * spamming the channel with the full programme.
     */
    private const int UPCOMING_LIMIT = 5;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $event = app(CurrentEvent::class)->get();

        if ($event === null) {
            return InteractionResponse::message(__('discord.commands.schedule.no_current_event'));
        }

        $items = ScheduleItem::query()
            ->where('event_id', $event->id)
            ->where('starts_at', '>=', Carbon::now())
            ->orderBy('starts_at')
            ->orderBy('sort')
            ->limit(self::UPCOMING_LIMIT)
            ->get();

        if ($items->isEmpty()) {
            return InteractionResponse::message(__('discord.commands.schedule.none'));
        }

        $lines = $items
            ->map(fn (ScheduleItem $item): string => __('discord.commands.schedule.item', [
                'title' => $item->title,
                'starts_at' => $item->starts_at->toDateTimeString(),
            ]))
            ->implode("\n");

        $content = __('discord.commands.schedule.heading')."\n".$lines;

        return InteractionResponse::message($content);
    }
}
