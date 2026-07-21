<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Stats\Support\EventBadgeCalculator;
use App\Modules\Voting\Enums\PollKind;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollOption;
use App\Modules\Voting\Models\PollVote;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('awards mvp_of_the_night to the closed MVP poll winner', function () {
    $event = Event::factory()->create();
    $winnerUser = User::factory()->create();
    $runnerUpUser = User::factory()->create();

    $poll = Poll::factory()->for($event)->closed()->create(['kind' => PollKind::Mvp]);
    $winnerOption = PollOption::factory()->for($poll)->create(['sort' => 0]);
    $winnerOption->forceFill(['subject_user_id' => $winnerUser->id])->save();
    $runnerUpOption = PollOption::factory()->for($poll)->create(['sort' => 1]);
    $runnerUpOption->forceFill(['subject_user_id' => $runnerUpUser->id])->save();

    PollVote::factory()->for($poll)->for($winnerOption, 'option')->count(2)->create();
    PollVote::factory()->for($poll)->for($runnerUpOption, 'option')->create();

    $badges = EventBadgeCalculator::forEvent($event);

    expect($badges[$winnerUser->id])->toContain('mvp_of_the_night')
        ->and($badges)->not->toHaveKey($runnerUpUser->id);
});

it('returns an empty array when the event has no closed MVP poll', function () {
    $event = Event::factory()->create();

    expect(EventBadgeCalculator::forEvent($event))->toBe([]);
});

it('returns an empty array when the MVP poll is still open', function () {
    $event = Event::factory()->create();
    $poll = Poll::factory()->for($event)->open()->create(['kind' => PollKind::Mvp]);
    $option = PollOption::factory()->for($poll)->create();
    $option->forceFill(['subject_user_id' => User::factory()->create()->id])->save();

    expect(EventBadgeCalculator::forEvent($event))->toBe([]);
});

it('returns an empty array when the closed MVP poll has no options', function () {
    $event = Event::factory()->create();
    Poll::factory()->for($event)->closed()->create(['kind' => PollKind::Mvp]);

    expect(EventBadgeCalculator::forEvent($event))->toBe([]);
});

it('returns an empty array when the closed MVP poll has options but zero votes', function () {
    $event = Event::factory()->create();
    $poll = Poll::factory()->for($event)->closed()->create(['kind' => PollKind::Mvp]);
    $option = PollOption::factory()->for($poll)->create(['sort' => 0]);
    $option->forceFill(['subject_user_id' => User::factory()->create()->id])->save();

    expect(EventBadgeCalculator::forEvent($event))->toBe([]);
});
