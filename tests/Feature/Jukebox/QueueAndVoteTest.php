<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Actions\AddToQueue;
use App\Modules\Jukebox\Actions\ToggleVote;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Exceptions\JukeboxException;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Jukebox\Models\JukeboxVote;
use App\Modules\Jukebox\Support\JukeboxQueue;
use App\Modules\Jukebox\Support\TrackDto;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Event, 1: User}
 */
function checkedInUser(): array
{
    $event = Event::factory()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->checkedIn()->create([
        'event_id' => $event->id,
        'user_id' => $user->id,
    ]);

    return [$event, $user];
}

function track(string $uri): TrackDto
{
    return new TrackDto(uri: $uri, title: "Track {$uri}");
}

it('lets a checked-in user queue one track but blocks a second unplayed one', function () {
    [$event, $user] = checkedInUser();

    app(AddToQueue::class)->handle($user, $event, track('a'));

    expect(fn () => app(AddToQueue::class)->handle($user, $event, track('b')))
        ->toThrow(JukeboxException::class);
});

it('refuses queue/vote from a non-checked-in user', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();

    expect(fn () => app(AddToQueue::class)->handle($user, $event, track('a')))
        ->toThrow(JukeboxException::class);

    $item = JukeboxItem::factory()->create(['event_id' => $event->id]);

    expect(fn () => app(ToggleVote::class)->handle($user, $item))
        ->toThrow(JukeboxException::class);
});

it('refuses queue/vote from a user who checked in but then had their registration cancelled', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->checkedIn()->create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => RegistrationStatus::Cancelled,
    ]);

    expect(fn () => app(AddToQueue::class)->handle($user, $event, track('a')))
        ->toThrow(JukeboxException::class);

    $item = JukeboxItem::factory()->create(['event_id' => $event->id]);

    expect(fn () => app(ToggleVote::class)->handle($user, $item))
        ->toThrow(JukeboxException::class);
});

it('lets a checked-in user queue again once their previous item has been played', function () {
    [$event, $user] = checkedInUser();

    $first = app(AddToQueue::class)->handle($user, $event, track('a'));
    $first->forceFill(['status' => QueueItemStatus::Played])->save();

    $second = app(AddToQueue::class)->handle($user, $event, track('b'));

    expect($second->uri)->toBe('b');
});

it('toggles a user up-vote for an item', function () {
    [$event, $user] = checkedInUser();
    $item = JukeboxItem::factory()->create(['event_id' => $event->id]);

    app(ToggleVote::class)->handle($user, $item);
    expect(JukeboxVote::query()->where('jukebox_item_id', $item->id)->where('user_id', $user->id)->exists())->toBeTrue();

    app(ToggleVote::class)->handle($user, $item);
    expect(JukeboxVote::query()->where('jukebox_item_id', $item->id)->where('user_id', $user->id)->exists())->toBeFalse();
});

it('orders the upcoming queue by net up-votes then age', function () {
    [$event] = checkedInUser();

    $a = JukeboxItem::factory()->create(['event_id' => $event->id, 'uri' => 'a', 'created_at' => now()->subMinutes(5)]);
    $b = JukeboxItem::factory()->create(['event_id' => $event->id, 'uri' => 'b', 'created_at' => now()->subMinutes(1)]);

    $voters = User::factory()->count(3)->create();
    JukeboxVote::factory()->create(['jukebox_item_id' => $b->id, 'user_id' => $voters[0]->id]);
    JukeboxVote::factory()->create(['jukebox_item_id' => $b->id, 'user_id' => $voters[1]->id]);
    JukeboxVote::factory()->create(['jukebox_item_id' => $a->id, 'user_id' => $voters[2]->id]);

    expect(app(JukeboxQueue::class)->upcoming($event)->pluck('uri')->all())->toBe(['b', 'a']);
});

it('orders items with equal votes by creation age ascending', function () {
    [$event] = checkedInUser();

    $older = JukeboxItem::factory()->create(['event_id' => $event->id, 'uri' => 'older', 'created_at' => now()->subMinutes(10)]);
    $newer = JukeboxItem::factory()->create(['event_id' => $event->id, 'uri' => 'newer', 'created_at' => now()->subMinutes(1)]);

    expect(app(JukeboxQueue::class)->upcoming($event)->pluck('uri')->all())->toBe(['older', 'newer']);
});

it('returns the currently playing item', function () {
    [$event] = checkedInUser();

    JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Queued]);
    $playing = JukeboxItem::factory()->create(['event_id' => $event->id, 'status' => QueueItemStatus::Playing]);

    expect(app(JukeboxQueue::class)->current($event)?->id)->toBe($playing->id);
});

it('returns null current when nothing is playing', function () {
    [$event] = checkedInUser();

    expect(app(JukeboxQueue::class)->current($event))->toBeNull();
});
