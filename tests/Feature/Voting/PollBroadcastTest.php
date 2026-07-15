<?php

use App\Models\User;
use App\Modules\Voting\Actions\CastVote;
use App\Modules\Voting\Events\PollUpdated;
use App\Modules\Voting\Models\Poll;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('implements ShouldBroadcast and ShouldDispatchAfterCommit', function () {
    $poll = Poll::factory()->make(['id' => 1, 'event_id' => 1]);

    $event = new PollUpdated($poll);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class);
});

it('broadcasts on the public event.{id} channel with the poll.updated name', function () {
    $poll = Poll::factory()->create();

    $event = new PollUpdated($poll);
    $channel = $event->broadcastOn();

    expect($channel)->toBeInstanceOf(Channel::class)
        ->and($channel->name)->toBe('event.'.$poll->event_id)
        ->and($event->broadcastAs())->toBe('poll.updated');
});

it('carries the current tallies in broadcastWith', function () {
    $poll = Poll::factory()->open()->withOptions(2)->create();
    [$optionA, $optionB] = $poll->options;
    $user = User::factory()->create();

    app(CastVote::class)->handle($poll, $user, $optionA);

    $event = new PollUpdated($poll->fresh());
    $payload = $event->broadcastWith();

    expect($payload['pollId'])->toBe($poll->id)
        ->and($payload['totalVotes'])->toBe(1);

    $tallyForA = collect($payload['options'])->firstWhere('id', $optionA->id);
    $tallyForB = collect($payload['options'])->firstWhere('id', $optionB->id);

    expect($tallyForA['count'])->toBe(1)
        ->and($tallyForA['percent'])->toBe(100.0)
        ->and($tallyForB['count'])->toBe(0)
        ->and($tallyForB['percent'])->toBe(0.0);
});

it('dispatches PollUpdated when a vote is cast', function () {
    Event::fake([PollUpdated::class]);

    $poll = Poll::factory()->open()->withOptions(2)->create();
    $user = User::factory()->create();
    $option = $poll->options->first();

    app(CastVote::class)->handle($poll, $user, $option);

    Event::assertDispatched(PollUpdated::class, fn (PollUpdated $event) => $event->poll->is($poll));
});
