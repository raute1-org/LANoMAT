<?php

declare(strict_types=1);

use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Jukebox\Models\JukeboxVote;
use App\Modules\Jukebox\Support\JukeboxQueue;
use Illuminate\Support\Facades\DB;

it('loads the upcoming queue with a bounded number of queries regardless of item count', function () {
    $event = Event::factory()->create();

    $items = JukeboxItem::factory()->count(5)->create([
        'event_id' => $event->id,
        'status' => QueueItemStatus::Queued,
    ]);

    foreach ($items as $item) {
        JukeboxVote::factory()->create(['jukebox_item_id' => $item->id]);
    }

    DB::enableQueryLog();
    $upcoming = app(JukeboxQueue::class)->upcoming($event);
    // Force lazy relations to resolve so an N+1 would show up in the log.
    $upcoming->each(fn (JukeboxItem $item) => [$item->addedBy?->name, $item->votes->count()]);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($upcoming)->toHaveCount(5)
        ->and($queryCount)->toBeLessThanOrEqual(3);
});

it('only includes queued items, ordered by vote count desc then age asc', function () {
    $event = Event::factory()->create();

    $skipped = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Skipped]);
    $played = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Played]);
    $playing = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Playing]);
    $queued = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Queued]);

    $upcoming = app(JukeboxQueue::class)->upcoming($event);

    expect($upcoming->pluck('id')->all())->toBe([$queued->id])
        ->and($upcoming->pluck('id')->all())->not->toContain($skipped->id, $played->id, $playing->id);
});
