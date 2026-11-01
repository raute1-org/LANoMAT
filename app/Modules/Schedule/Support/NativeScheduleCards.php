<?php

declare(strict_types=1);

namespace App\Modules\Schedule\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\WebVoice\Contracts\ScheduleCardSource;
use App\Modules\WebVoice\Domain\NowCard;
use Illuminate\Support\Carbon;

/**
 * The native client's "now" card for the Schedule: the programme item that is running or, failing
 * that, the next one, from the same projection as the public programme page.
 */
class NativeScheduleCards implements ScheduleCardSource
{
    public function cards(User $user, Event $event): array
    {
        $items = ScheduleProjection::itemsFor($event);
        $now = Carbon::now();

        $current = ScheduleProjection::currentItem($items, $now);
        $item = ScheduleProjection::nextItem($items, $now) ?? $current;

        if ($item === null) {
            return [];
        }

        $isNext = $item !== $current;

        return [new NowCard(
            kind: NowCard::KIND_SCHEDULE,
            id: 'schedule:'.$item['id'],
            state: $isNext ? 'next' : 'running',
            priority: 90,
            title: trans('native_client.now.schedule_next', ['title' => (string) $item['title']]),
            at: Carbon::parse((string) $item['startsAt']),
            data: ['item' => $item],
        )];
    }
}
