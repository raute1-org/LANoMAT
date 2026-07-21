<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Voting\Actions\RevealMvp;
use App\Modules\Voting\Enums\PollKind;
use App\Modules\Voting\Exceptions\VotingException;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollOption;
use App\Modules\Voting\Models\PollVote;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;

uses(RefreshDatabase::class);

it('dispatches a SceneOverride carrying only the public winner name', function () {
    EventFacade::fake([SceneOverride::class]);

    $event = Event::factory()->create();
    $orga = User::factory()->orga()->create();
    $winnerUser = User::factory()->create(['name' => 'Ada Lovelace']);

    $poll = Poll::factory()->for($event)->closed()->create(['kind' => PollKind::Mvp]);
    $winnerOption = PollOption::factory()->for($poll)->create(['sort' => 0, 'label' => 'Ada Lovelace']);
    $winnerOption->forceFill(['subject_user_id' => $winnerUser->id])->save();
    $otherOption = PollOption::factory()->for($poll)->create(['sort' => 1]);

    PollVote::factory()->for($poll)->for($winnerOption, 'option')->count(2)->create();
    PollVote::factory()->for($poll)->for($otherOption, 'option')->create();

    app(RevealMvp::class)->handle($poll, $orga);

    EventFacade::assertDispatched(SceneOverride::class, function (SceneOverride $dispatched) use ($event): bool {
        expect($dispatched->scene['data'])->not->toHaveKey('user_id');

        return $dispatched->eventId === $event->id
            && $dispatched->scene['type'] === SceneType::Winner->value
            && $dispatched->scene['durationSec'] === 20
            && $dispatched->scene['data']['winner'] === 'Ada Lovelace';
    });
});

it('rejects a non-orga actor with AuthorizationException', function () {
    $event = Event::factory()->create();
    $participant = User::factory()->create();

    $poll = Poll::factory()->for($event)->closed()->create(['kind' => PollKind::Mvp]);
    $option = PollOption::factory()->for($poll)->create();
    $option->forceFill(['subject_user_id' => User::factory()->create()->id])->save();

    expect(fn () => app(RevealMvp::class)->handle($poll, $participant))
        ->toThrow(AuthorizationException::class);
});

it('rejects a poll that is not the closed MVP poll', function () {
    $event = Event::factory()->create();
    $orga = User::factory()->orga()->create();

    $openMvpPoll = Poll::factory()->for($event)->open()->create(['kind' => PollKind::Mvp]);

    expect(fn () => app(RevealMvp::class)->handle($openMvpPoll, $orga))
        ->toThrow(VotingException::class);
});

it('rejects a closed standard (non-MVP) poll', function () {
    $event = Event::factory()->create();
    $orga = User::factory()->orga()->create();

    $standardPoll = Poll::factory()->for($event)->closed()->create(['kind' => PollKind::Standard]);

    expect(fn () => app(RevealMvp::class)->handle($standardPoll, $orga))
        ->toThrow(VotingException::class);
});
