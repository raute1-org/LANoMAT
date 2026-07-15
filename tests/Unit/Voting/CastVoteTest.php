<?php

use App\Models\User;
use App\Modules\Voting\Actions\CastVote;
use App\Modules\Voting\Exceptions\VotingException;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollVote;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function castVote(Poll $poll, User $user, $option): PollVote
{
    return app(CastVote::class)->handle($poll, $user, $option);
}

it('casts a vote for an open poll', function () {
    $poll = Poll::factory()->open()->withOptions(2)->create();
    $user = User::factory()->create();
    $option = $poll->options->first();

    $vote = castVote($poll, $user, $option);

    expect($vote)->toBeInstanceOf(PollVote::class)
        ->and($vote->exists)->toBeTrue()
        ->and($vote->poll_id)->toBe($poll->id)
        ->and($vote->poll_option_id)->toBe($option->id)
        ->and($vote->user_id)->toBe($user->id);

    expect(PollVote::query()->count())->toBe(1);
});

it('rejects a second vote by the same user on the same poll', function () {
    $poll = Poll::factory()->open()->withOptions(2)->create();
    $user = User::factory()->create();
    [$optionA, $optionB] = $poll->options;

    castVote($poll, $user, $optionA);

    expect(fn () => castVote($poll, $user, $optionB))
        ->toThrow(VotingException::class);

    expect(PollVote::query()->count())->toBe(1);
});

it('rejects voting on a draft poll', function () {
    $poll = Poll::factory()->withOptions(2)->create();
    $user = User::factory()->create();

    expect(fn () => castVote($poll, $user, $poll->options->first()))
        ->toThrow(VotingException::class);

    expect(PollVote::query()->count())->toBe(0);
});

it('rejects voting on a closed poll', function () {
    $poll = Poll::factory()->closed()->withOptions(2)->create();
    $user = User::factory()->create();

    expect(fn () => castVote($poll, $user, $poll->options->first()))
        ->toThrow(VotingException::class);

    expect(PollVote::query()->count())->toBe(0);
});

it('rejects an option that belongs to a different poll', function () {
    $poll = Poll::factory()->open()->withOptions(2)->create();
    $otherPoll = Poll::factory()->open()->withOptions(1)->create();
    $user = User::factory()->create();

    $foreignOption = $otherPoll->options->first();

    expect(fn () => castVote($poll, $user, $foreignOption))
        ->toThrow(VotingException::class);

    expect(PollVote::query()->count())->toBe(0);
});

it('always records the passed-in authenticated user, never a client-forged id', function () {
    $poll = Poll::factory()->open()->withOptions(2)->create();
    $realUser = User::factory()->create();
    $impersonated = User::factory()->create();

    // Even though a bogus user id could theoretically be smuggled in via
    // some other channel, CastVote::handle only ever accepts a full User
    // model (resolved by the controller from auth()) and assigns its id
    // directly — there is no parameter through which a raw/forged user_id
    // could reach the vote.
    $vote = castVote($poll, $realUser, $poll->options->first());

    expect($vote->user_id)->toBe($realUser->id)
        ->and($vote->user_id)->not->toBe($impersonated->id);
});
