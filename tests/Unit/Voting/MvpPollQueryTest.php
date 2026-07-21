<?php

use App\Modules\Events\Models\Event;
use App\Modules\Voting\Enums\PollKind;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollOption;
use App\Modules\Voting\Models\PollVote;
use App\Modules\Voting\Support\MvpPollQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns null when the event has no MVP poll', function () {
    $event = Event::factory()->create();

    expect(MvpPollQuery::closedFor($event))->toBeNull();
});

it('returns null when the MVP poll is not yet closed', function () {
    $event = Event::factory()->create();
    Poll::factory()->for($event)->create(['kind' => PollKind::Mvp]);

    expect(MvpPollQuery::closedFor($event))->toBeNull();
});

it('ignores a closed standard poll and finds the closed MVP poll', function () {
    $event = Event::factory()->create();
    Poll::factory()->for($event)->closed()->create(['kind' => PollKind::Standard]);
    $mvpPoll = Poll::factory()->for($event)->closed()->create(['kind' => PollKind::Mvp]);

    $found = MvpPollQuery::closedFor($event);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($mvpPoll->id);
});

it('returns the option with the most votes as the winner', function () {
    $poll = Poll::factory()->closed()->create(['kind' => PollKind::Mvp]);
    $lowOption = PollOption::factory()->for($poll)->create(['sort' => 0]);
    $highOption = PollOption::factory()->for($poll)->create(['sort' => 1]);

    PollVote::factory()->for($poll)->for($lowOption, 'option')->create();
    PollVote::factory()->for($poll)->for($highOption, 'option')->create();
    PollVote::factory()->for($poll)->for($highOption, 'option')->create();

    $winner = MvpPollQuery::winner($poll);

    expect($winner->id)->toBe($highOption->id);
});

it('breaks a vote tie deterministically by earliest sort', function () {
    $poll = Poll::factory()->closed()->create(['kind' => PollKind::Mvp]);
    $earlier = PollOption::factory()->for($poll)->create(['sort' => 0]);
    $later = PollOption::factory()->for($poll)->create(['sort' => 1]);

    PollVote::factory()->for($poll)->for($earlier, 'option')->create();
    PollVote::factory()->for($poll)->for($later, 'option')->create();

    $winner = MvpPollQuery::winner($poll);

    expect($winner->id)->toBe($earlier->id);
});

it('returns null when the poll has no options', function () {
    $poll = Poll::factory()->closed()->create(['kind' => PollKind::Mvp]);

    expect(MvpPollQuery::winner($poll))->toBeNull();
});
