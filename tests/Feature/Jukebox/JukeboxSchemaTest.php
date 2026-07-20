<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Jukebox\Models\JukeboxSkipVote;
use App\Modules\Jukebox\Models\JukeboxVote;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('enforces a single up-vote per user per item', function () {
    $item = JukeboxItem::factory()->create();
    $user = User::factory()->create();
    JukeboxVote::factory()->create(['jukebox_item_id' => $item->id, 'user_id' => $user->id]);

    expect(fn () => JukeboxVote::factory()->create(['jukebox_item_id' => $item->id, 'user_id' => $user->id]))
        ->toThrow(QueryException::class);
});

it('enforces a single skip-vote per user per item', function () {
    $item = JukeboxItem::factory()->create();
    $user = User::factory()->create();
    JukeboxSkipVote::factory()->create(['jukebox_item_id' => $item->id, 'user_id' => $user->id]);

    expect(fn () => JukeboxSkipVote::factory()->create(['jukebox_item_id' => $item->id, 'user_id' => $user->id]))
        ->toThrow(QueryException::class);
});

it('cascades deletes from event to jukebox items', function () {
    $event = Event::factory()->create();
    $item = JukeboxItem::factory()->create(['event_id' => $event->id]);

    $event->delete();

    expect(JukeboxItem::find($item->id))->toBeNull();
});

it('cascades deletes from jukebox item to its votes and skip votes', function () {
    $item = JukeboxItem::factory()->create();
    $vote = JukeboxVote::factory()->create(['jukebox_item_id' => $item->id]);
    $skipVote = JukeboxSkipVote::factory()->create(['jukebox_item_id' => $item->id]);

    $item->delete();

    expect(JukeboxVote::find($vote->id))->toBeNull()
        ->and(JukeboxSkipVote::find($skipVote->id))->toBeNull();
});

it('cascades deletes from user to added items, votes, and skip votes', function () {
    $user = User::factory()->create();
    $item = JukeboxItem::factory()->create(['added_by' => $user->id]);
    $vote = JukeboxVote::factory()->create(['user_id' => $user->id]);
    $skipVote = JukeboxSkipVote::factory()->create(['user_id' => $user->id]);

    $user->delete();

    expect(JukeboxItem::find($item->id))->toBeNull()
        ->and(JukeboxVote::find($vote->id))->toBeNull()
        ->and(JukeboxSkipVote::find($skipVote->id))->toBeNull();
});
