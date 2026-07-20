<?php

declare(strict_types=1);

use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Events\JukeboxUpdated;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Jukebox\Support\NowPlayingDto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;

uses(RefreshDatabase::class);

it('promotes the next queued item once Music Assistant reports the current one finished', function () {
    EventFacade::fake([JukeboxUpdated::class]);
    $fake = fakeMusic();
    $event = Event::factory()->live()->create();

    $playing = JukeboxItem::factory()->create([
        'event_id' => $event->id,
        'status' => QueueItemStatus::Playing,
        'uri' => 'ma://track/finished',
    ]);
    $next = JukeboxItem::factory()->create([
        'event_id' => $event->id,
        'status' => QueueItemStatus::Queued,
        'uri' => 'ma://track/next',
    ]);
    $afterNext = JukeboxItem::factory()->create([
        'event_id' => $event->id,
        'status' => QueueItemStatus::Queued,
        'uri' => 'ma://track/after-next',
    ]);

    // MA no longer reports the previously-playing track: it has moved on to
    // something outside LANoMAT's own queue tracking (or stopped).
    $fake->willReturnNowPlaying(null);

    $this->artisan('lanomat:jukebox-tick')->assertExitCode(0);

    expect($playing->refresh()->status)->toBe(QueueItemStatus::Played);
    expect($playing->refresh()->played_at)->not->toBeNull();
    expect($next->refresh()->status)->toBe(QueueItemStatus::Playing);
    expect($afterNext->refresh()->status)->toBe(QueueItemStatus::Queued);
    // The synced queue mirrors what's still *upcoming* (queued) after the
    // promotion — the promoted item is now playing, not queued, so it is no
    // longer part of the upcoming list synced to Music Assistant, while the
    // still-queued item after it is.
    expect($fake->syncedQueue())->toBe(['ma://track/after-next']);
    EventFacade::assertDispatched(JukeboxUpdated::class, fn (JukeboxUpdated $e) => $e->eventId === $event->id);
});

it('leaves the current item playing when Music Assistant still reports the same uri', function () {
    EventFacade::fake([JukeboxUpdated::class]);
    $fake = fakeMusic();
    $event = Event::factory()->live()->create();

    $playing = JukeboxItem::factory()->create([
        'event_id' => $event->id,
        'status' => QueueItemStatus::Playing,
        'uri' => 'ma://track/still-going',
    ]);

    $fake->willReturnNowPlaying(new NowPlayingDto(
        uri: 'ma://track/still-going',
        title: 'Still Going',
        artist: null,
        durationSeconds: 200,
        positionSeconds: 30,
        isPlaying: true,
    ));

    $this->artisan('lanomat:jukebox-tick')->assertExitCode(0);

    expect($playing->refresh()->status)->toBe(QueueItemStatus::Playing);
    EventFacade::assertNotDispatched(JukeboxUpdated::class);
});

it('starts playback by promoting the top queued item when nothing is playing yet', function () {
    EventFacade::fake([JukeboxUpdated::class]);
    $fake = fakeMusic();
    $event = Event::factory()->live()->create();

    $next = JukeboxItem::factory()->create([
        'event_id' => $event->id,
        'status' => QueueItemStatus::Queued,
        'uri' => 'ma://track/first',
    ]);

    $fake->willReturnNowPlaying(null);

    $this->artisan('lanomat:jukebox-tick')->assertExitCode(0);

    expect($next->refresh()->status)->toBe(QueueItemStatus::Playing);
    EventFacade::assertDispatched(JukeboxUpdated::class, fn (JukeboxUpdated $e) => $e->eventId === $event->id);
});

it('ignores events whose jukebox is not active (not live)', function () {
    EventFacade::fake([JukeboxUpdated::class]);
    $fake = fakeMusic();
    $event = Event::factory()->finished()->create();

    JukeboxItem::factory()->create([
        'event_id' => $event->id,
        'status' => QueueItemStatus::Queued,
        'uri' => 'ma://track/first',
    ]);

    $this->artisan('lanomat:jukebox-tick')->assertExitCode(0);

    expect($fake->syncedQueue())->toBe([]);
    EventFacade::assertNotDispatched(JukeboxUpdated::class);
});

it('does not throw when Music Assistant is unavailable during the tick', function () {
    EventFacade::fake([JukeboxUpdated::class]);
    $fake = fakeMusic();
    $fake->willBeUnavailable();
    $event = Event::factory()->live()->create();

    JukeboxItem::factory()->create([
        'event_id' => $event->id,
        'status' => QueueItemStatus::Playing,
        'uri' => 'ma://track/whatever',
    ]);

    $this->artisan('lanomat:jukebox-tick')->assertExitCode(0);

    EventFacade::assertNotDispatched(JukeboxUpdated::class);
})->throwsNoExceptions();
