<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Jukebox\Models\JukeboxSkipVote;
use App\Modules\Jukebox\Models\JukeboxVote;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults new items to queued status via the factory', function () {
    $item = JukeboxItem::factory()->create();

    expect($item->status)->toBe(QueueItemStatus::Queued)
        ->and($item->status)->toBeInstanceOf(QueueItemStatus::class);
});

it('does not mass-assign status or played_at', function () {
    $item = new JukeboxItem([
        'event_id' => 1,
        'added_by' => 1,
        'uri' => 'ma://track/x',
        'title' => 'Song',
        'status' => QueueItemStatus::Played,
        'played_at' => now(),
    ]);

    expect($item->status)->toBeNull()
        ->and($item->played_at)->toBeNull();
});

it('resolves the event and addedBy relations', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();
    $item = JukeboxItem::factory()->create(['event_id' => $event->id, 'added_by' => $user->id]);

    expect($item->event->is($event))->toBeTrue()
        ->and($item->addedBy->is($user))->toBeTrue();
});

it('casts duration_seconds to integer and played_at to a Carbon instance', function () {
    $item = JukeboxItem::factory()->create();
    $item->forceFill(['played_at' => now()])->save();
    $item->refresh();

    expect($item->duration_seconds === null || is_int($item->duration_seconds))->toBeTrue()
        ->and($item->played_at)->toBeInstanceOf(CarbonInterface::class);
});

it('counts votes via voteCount()', function () {
    $item = JukeboxItem::factory()->create();
    JukeboxVote::factory()->count(3)->sequence(
        ['user_id' => User::factory()->create()->id],
        ['user_id' => User::factory()->create()->id],
        ['user_id' => User::factory()->create()->id],
    )->create(['jukebox_item_id' => $item->id]);

    expect($item->voteCount())->toBe(3);
});

it('exposes votes and skipVotes relations', function () {
    $item = JukeboxItem::factory()->create();
    $vote = JukeboxVote::factory()->create(['jukebox_item_id' => $item->id]);
    $skipVote = JukeboxSkipVote::factory()->create(['jukebox_item_id' => $item->id]);

    expect($item->votes()->pluck('id'))->toContain($vote->id)
        ->and($item->skipVotes()->pluck('id'))->toContain($skipVote->id);
});
