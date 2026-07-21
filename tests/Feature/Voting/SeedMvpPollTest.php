<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Voting\Actions\SeedMvpPoll;
use App\Modules\Voting\Enums\PollKind;
use App\Modules\Voting\Enums\PollStatus;
use App\Modules\Voting\Exceptions\VotingException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds one option per registered participant for the MVP poll', function () {
    $event = Event::factory()->create();
    $orga = User::factory()->orga()->create();
    EventRegistration::factory()->count(4)->create(['event_id' => $event->id]);

    $poll = app(SeedMvpPoll::class)->handle($event, $orga);

    expect($poll->kind)->toBe(PollKind::Mvp)
        ->and($poll->status)->toBe(PollStatus::Draft)
        ->and($poll->question)->toBe(trans('polls.mvp.question'))
        ->and($poll->options()->count())->toBe(4);
});

it('excludes cancelled registrations from the seeded options', function () {
    $event = Event::factory()->create();
    $orga = User::factory()->orga()->create();
    EventRegistration::factory()->count(3)->create(['event_id' => $event->id]);
    EventRegistration::factory()->cancelled()->create(['event_id' => $event->id]);

    $poll = app(SeedMvpPoll::class)->handle($event, $orga);

    expect($poll->options()->count())->toBe(3);
});

it('labels each seeded option with the participant display name', function () {
    $event = Event::factory()->create();
    $orga = User::factory()->orga()->create();
    $participant = User::factory()->create(['name' => 'Ada Lovelace']);
    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $participant->id]);

    $poll = app(SeedMvpPoll::class)->handle($event, $orga);

    expect($poll->options()->pluck('label')->all())->toContain('Ada Lovelace');
});

it('links each seeded option back to the participant via subject_user_id', function () {
    $event = Event::factory()->create();
    $orga = User::factory()->orga()->create();
    $participant = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $participant->id]);

    $poll = app(SeedMvpPoll::class)->handle($event, $orga);

    $option = $poll->options()->first();

    expect($option->subject_user_id)->toBe($participant->id);
});

it('rejects seeding a second MVP poll for the same event', function () {
    $event = Event::factory()->create();
    $orga = User::factory()->orga()->create();
    EventRegistration::factory()->count(2)->create(['event_id' => $event->id]);

    app(SeedMvpPoll::class)->handle($event, $orga);

    expect(fn () => app(SeedMvpPoll::class)->handle($event, $orga))
        ->toThrow(VotingException::class);

    try {
        app(SeedMvpPoll::class)->handle($event, $orga);
    } catch (VotingException $e) {
        expect($e->translationKey)->toBe('polls.errors.mvp_poll_exists');
    }
});

it('rejects a participant (non-orga) with AuthorizationException', function () {
    $event = Event::factory()->create();
    $participant = User::factory()->create();
    EventRegistration::factory()->count(2)->create(['event_id' => $event->id]);

    expect(fn () => app(SeedMvpPoll::class)->handle($event, $participant))
        ->toThrow(AuthorizationException::class);
});
