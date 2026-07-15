<?php

use App\Models\User;
use App\Modules\Voting\Enums\PollStatus;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollOption;
use App\Modules\Voting\Models\PollVote;
use Illuminate\Database\QueryException;

it('has German labels for every poll status', function () {
    expect(PollStatus::Draft->label())->toBe('Entwurf')
        ->and(PollStatus::Open->label())->toBe('Offen')
        ->and(PollStatus::Closed->label())->toBe('Geschlossen');
});

it('builds a poll with options via the factory', function () {
    $poll = Poll::factory()->withOptions(3)->create();

    expect($poll->options)->toHaveCount(3)
        ->and($poll->options->first())->toBeInstanceOf(PollOption::class);
});

it('reports open status via factory state', function () {
    $poll = Poll::factory()->open()->create();

    expect($poll->status)->toBe(PollStatus::Open)
        ->and($poll->isOpenNow())->toBeTrue();
});

it('reports closed status via factory state', function () {
    $poll = Poll::factory()->closed()->create();

    expect($poll->status)->toBe(PollStatus::Closed)
        ->and($poll->isOpenNow())->toBeFalse();
});

it('is not open now once past its closes_at deadline', function () {
    $poll = Poll::factory()->open()->create(['closes_at' => now()->subMinute()]);

    expect($poll->isOpenNow())->toBeFalse();
});

it('enforces one vote per user per poll at the database level', function () {
    $poll = Poll::factory()->withOptions(2)->create();
    $user = User::factory()->create();
    [$optionA, $optionB] = $poll->options;

    PollVote::factory()->create([
        'poll_id' => $poll->id,
        'poll_option_id' => $optionA->id,
        'user_id' => $user->id,
    ]);

    expect(fn () => PollVote::factory()->create([
        'poll_id' => $poll->id,
        'poll_option_id' => $optionB->id,
        'user_id' => $user->id,
    ]))->toThrow(function (QueryException $e) {
        expect($e->getCode())->toBe('23505');
    });
});

it('tallies votes cast for an option', function () {
    $poll = Poll::factory()->withOptions(2)->create();
    [$optionA, $optionB] = $poll->options;

    PollVote::factory()->for($poll)->for($optionA, 'option')->create();
    PollVote::factory()->for($poll)->for($optionA, 'option')->create();
    PollVote::factory()->for($poll)->for($optionB, 'option')->create();

    expect($optionA->tally())->toBe(2)
        ->and($optionB->tally())->toBe(1);
});
