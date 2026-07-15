<?php

use App\Modules\Voting\Actions\ClosePoll;
use App\Modules\Voting\Actions\OpenPoll;
use App\Modules\Voting\Enums\PollStatus;
use App\Modules\Voting\Exceptions\VotingException;
use App\Modules\Voting\Models\Poll;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function openPoll(Poll $poll): Poll
{
    return app(OpenPoll::class)->handle($poll);
}

function closePoll(Poll $poll): Poll
{
    return app(ClosePoll::class)->handle($poll);
}

// --- OpenPoll -----------------------------------------------------------

it('opens a draft poll', function () {
    $poll = Poll::factory()->create();

    $result = openPoll($poll);

    expect($result->status)->toBe(PollStatus::Open)
        ->and($result->id)->toBe($poll->id);

    expect($poll->fresh()->status)->toBe(PollStatus::Open);
});

it('rejects opening an already open poll', function () {
    $poll = Poll::factory()->open()->create();

    expect(fn () => openPoll($poll))
        ->toThrow(VotingException::class);

    try {
        openPoll($poll);
    } catch (VotingException $e) {
        expect($e->translationKey)->toBe('polls.errors.already_open');
    }

    expect($poll->fresh()->status)->toBe(PollStatus::Open);
});

it('rejects opening a closed poll', function () {
    $poll = Poll::factory()->closed()->create();

    expect(fn () => openPoll($poll))
        ->toThrow(VotingException::class);

    try {
        openPoll($poll);
    } catch (VotingException $e) {
        // OpenPoll only special-cases the Draft -> Open transition; any
        // other current status (including Closed) is rejected with the
        // same "already open" guard, per the action's actual `!== Draft`
        // check.
        expect($e->translationKey)->toBe('polls.errors.already_open');
    }

    expect($poll->fresh()->status)->toBe(PollStatus::Closed);
});

// --- ClosePoll ------------------------------------------------------------

it('closes an open poll', function () {
    $poll = Poll::factory()->open()->create();

    $result = closePoll($poll);

    expect($result->status)->toBe(PollStatus::Closed)
        ->and($result->id)->toBe($poll->id);

    expect($poll->fresh()->status)->toBe(PollStatus::Closed);
});

it('rejects closing a draft poll', function () {
    $poll = Poll::factory()->create();

    expect(fn () => closePoll($poll))
        ->toThrow(VotingException::class);

    try {
        closePoll($poll);
    } catch (VotingException $e) {
        expect($e->translationKey)->toBe('polls.errors.not_open_yet');
    }

    expect($poll->fresh()->status)->toBe(PollStatus::Draft);
});

it('rejects closing an already closed poll', function () {
    $poll = Poll::factory()->closed()->create();

    expect(fn () => closePoll($poll))
        ->toThrow(VotingException::class);

    try {
        closePoll($poll);
    } catch (VotingException $e) {
        expect($e->translationKey)->toBe('polls.errors.already_closed');
    }

    expect($poll->fresh()->status)->toBe(PollStatus::Closed);
});
