<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Actions\SyncQueueToPlayer;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Jukebox\Models\JukeboxVote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('mirrors the vote-ordered upcoming queue into Music Assistant', function () {
    $fake = fakeMusic();
    $event = Event::factory()->create();

    $low = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Queued, 'uri' => 'ma://track/low']);
    $high = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Queued, 'uri' => 'ma://track/high']);

    $voters = User::factory()->count(2)->create();
    foreach ($voters as $voter) {
        JukeboxVote::query()->create([
            'jukebox_item_id' => $high->id,
            'user_id' => $voter->id,
        ]);
    }

    app(SyncQueueToPlayer::class)->handle($event);

    expect($fake->syncedQueue())->toBe(['ma://track/high', 'ma://track/low']);
});

it('does not sync items belonging to another event', function () {
    $fake = fakeMusic();
    $event = Event::factory()->create();
    $otherEvent = Event::factory()->create();

    JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Queued, 'uri' => 'ma://track/mine']);
    JukeboxItem::factory()->create(['event_id' => $otherEvent->id, 'status' => QueueItemStatus::Queued, 'uri' => 'ma://track/other']);

    app(SyncQueueToPlayer::class)->handle($event);

    expect($fake->syncedQueue())->toBe(['ma://track/mine']);
});

it('logs a warning and does not throw when Music Assistant is unavailable', function () {
    Log::shouldReceive('warning')->once();

    $fake = fakeMusic();
    $fake->willBeUnavailable();
    $event = Event::factory()->create();
    JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Queued]);

    app(SyncQueueToPlayer::class)->handle($event);
})->throwsNoExceptions();
