<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Actions\RemoveItem;
use App\Modules\Jukebox\Actions\SkipCurrent;
use App\Modules\Jukebox\Actions\ToggleSkipVote;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Exceptions\JukeboxException;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Jukebox\Models\JukeboxSkipVote;
use App\Modules\Jukebox\Support\SkipThreshold;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Event, 1: User}
 */
function checkedInUserForSkip(): array
{
    $event = Event::factory()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->checkedIn()->create([
        'event_id' => $event->id,
        'user_id' => $user->id,
    ]);

    return [$event, $user];
}

it('computes the skip threshold as max(3, ceil(checkedIn * ratio))', function () {
    $event = Event::factory()->create();
    EventRegistration::factory()->count(2)->checkedIn()->create(['event_id' => $event->id]);

    // max(3, ceil(2 * 0.5)) = max(3, 1) = 3
    expect(SkipThreshold::for($event))->toBe(3);

    EventRegistration::factory()->count(8)->checkedIn()->create(['event_id' => $event->id]);
    // 10 checked in: max(3, ceil(10 * 0.5)) = max(3, 5) = 5
    expect(SkipThreshold::for($event))->toBe(5);
});

it('ignores non-checked-in registrations when computing the skip threshold', function () {
    $event = Event::factory()->create();
    EventRegistration::factory()->count(20)->create(['event_id' => $event->id]); // not checked in

    expect(SkipThreshold::for($event))->toBe(3);
});

it('skips the playing track once the skip threshold is reached', function () {
    $event = Event::factory()->create();
    // 6 checked-in users -> threshold = max(3, ceil(6 * 0.5)) = 3
    $voters = [];
    for ($i = 0; $i < 6; $i++) {
        $user = User::factory()->create();
        EventRegistration::factory()->checkedIn()->create(['event_id' => $event->id, 'user_id' => $user->id]);
        $voters[] = $user;
    }

    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Playing]);

    app(ToggleSkipVote::class)->handle($voters[0], $item);
    app(ToggleSkipVote::class)->handle($voters[1], $item);
    expect($item->refresh()->status)->toBe(QueueItemStatus::Playing);

    app(ToggleSkipVote::class)->handle($voters[2], $item);
    expect($item->refresh()->status)->toBe(QueueItemStatus::Skipped);
});

it('does not skip a queued (not yet playing) item even past threshold', function () {
    [$event, $user] = checkedInUserForSkip();
    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Queued]);

    app(ToggleSkipVote::class)->handle($user, $item);

    expect($item->refresh()->status)->toBe(QueueItemStatus::Queued);
});

it('toggles a skip vote off when cast twice', function () {
    [$event, $user] = checkedInUserForSkip();
    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Playing]);

    app(ToggleSkipVote::class)->handle($user, $item);
    expect(JukeboxSkipVote::query()->where('jukebox_item_id', $item->id)->where('user_id', $user->id)->exists())->toBeTrue();

    app(ToggleSkipVote::class)->handle($user, $item);
    expect(JukeboxSkipVote::query()->where('jukebox_item_id', $item->id)->where('user_id', $user->id)->exists())->toBeFalse();
});

it('refuses a skip vote from a non-checked-in user', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();
    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Playing]);

    expect(fn () => app(ToggleSkipVote::class)->handle($user, $item))
        ->toThrow(JukeboxException::class);
});

it('lets a helper skip the current track regardless of votes', function () {
    $event = Event::factory()->create();
    $helper = User::factory()->create(['role' => Role::Helper]);
    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Playing]);

    app(SkipCurrent::class)->handle($helper, $event);

    expect($item->refresh()->status)->toBe(QueueItemStatus::Skipped);
});

it('refuses a regular participant from using the orga skip override', function () {
    [$event, $user] = checkedInUserForSkip();
    JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Playing]);

    expect(fn () => app(SkipCurrent::class)->handle($user, $event))
        ->toThrow(JukeboxException::class);
});

it('lets a helper remove any item regardless of status', function () {
    $event = Event::factory()->create();
    $helper = User::factory()->create(['role' => Role::Helper]);
    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Queued]);

    app(RemoveItem::class)->handle($helper, $item);

    expect($item->refresh()->status)->toBe(QueueItemStatus::Skipped);
});

it('refuses a regular participant from removing an item', function () {
    [, $user] = checkedInUserForSkip();
    $item = JukeboxItem::factory()->create(['status' => QueueItemStatus::Queued]);

    expect(fn () => app(RemoveItem::class)->handle($user, $item))
        ->toThrow(JukeboxException::class);
});
