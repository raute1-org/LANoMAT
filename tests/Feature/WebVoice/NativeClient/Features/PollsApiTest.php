<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollVote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 12:00:00');
    $this->user = User::factory()->create();
    $this->headers = nativeBearer($this->user);
    $this->event = Event::factory()->live()->create();
});

function nativeVote(Poll $poll, User $user, int $optionId): void
{
    $vote = new PollVote(['poll_id' => $poll->id, 'poll_option_id' => $optionId]);
    $vote->forceFill(['user_id' => $user->id])->save();
}

it('lists the polls of an event with the own vote state, open and closed alike', function () {
    $open = Poll::factory()->open()->withOptions(2)->create(['event_id' => $this->event->id, 'question' => 'Map?']);
    $closed = Poll::factory()->closed()->withOptions(2)->create(['event_id' => $this->event->id]);
    Poll::factory()->open()->withOptions(2)->create(); // another event
    $option = $closed->options()->orderBy('sort')->firstOrFail();
    nativeVote($closed, $this->user, $option->id);
    nativeVote($open, User::factory()->create(), $open->options()->firstOrFail()->id);

    $response = $this->getJson("/api/voice-client/v1/events/{$this->event->slug}/polls", $this->headers)->assertOk();

    $polls = collect($response->json('polls'))->keyBy('id');
    expect($polls)->toHaveCount(2)
        ->and($polls[$open->id])->toMatchArray(['question' => 'Map?', 'status' => 'open', 'is_open' => true, 'total_votes' => 1, 'my_vote_option_id' => null, 'kind' => 'standard'])
        ->and($polls[$closed->id])->toMatchArray(['status' => 'closed', 'is_open' => false, 'total_votes' => 1, 'my_vote_option_id' => $option->id]);
});

it('reports a poll past its closing time as not open', function () {
    $poll = Poll::factory()->open()->withOptions(2)->create(['event_id' => $this->event->id, 'closes_at' => now()->subMinute()]);

    $this->getJson("/api/voice-client/v1/polls/{$poll->id}", $this->headers)
        ->assertOk()->assertJsonPath('status', 'open')->assertJsonPath('is_open', false);
});

it('shows poll detail with options, tallies and the own vote like the web page', function () {
    $poll = Poll::factory()->open()->withOptions(3)->create(['event_id' => $this->event->id]);
    $options = $poll->options()->orderBy('sort')->get();
    [$a, $b] = [$options[0], $options[1]];
    nativeVote($poll, $this->user, $a->id);
    nativeVote($poll, User::factory()->create(), $a->id);
    nativeVote($poll, User::factory()->create(), $b->id);

    $response = $this->getJson("/api/voice-client/v1/polls/{$poll->id}", $this->headers)->assertOk();

    expect($response->json('total_votes'))->toBe(3)
        ->and($response->json('my_vote_option_id'))->toBe($a->id)
        ->and($response->json('event_slug'))->toBe($this->event->slug)
        ->and($response->json('options.0'))->toMatchArray(['id' => $a->id, 'label' => $a->label, 'count' => 2, 'percent' => 66.7])
        ->and($response->json('options.1.percent'))->toBe(33.3)
        ->and($response->json('options.2.count'))->toBe(0);
});

it('hides polls of a draft event', function () {
    $draft = Event::factory()->draft()->create();
    $poll = Poll::factory()->open()->withOptions(2)->create(['event_id' => $draft->id]);

    $this->getJson("/api/voice-client/v1/events/{$draft->slug}/polls", $this->headers)->assertNotFound();
    $this->getJson("/api/voice-client/v1/polls/{$poll->id}", $this->headers)->assertNotFound();
    $this->postJson("/api/voice-client/v1/polls/{$poll->id}/vote", ['option_id' => $poll->options()->firstOrFail()->id], $this->headers)->assertNotFound();
    expect(PollVote::query()->count())->toBe(0);
});

it('casts a vote through CastVote and answers with the updated results', function () {
    $poll = Poll::factory()->open()->withOptions(2)->create(['event_id' => $this->event->id]);
    $option = $poll->options()->orderBy('sort')->firstOrFail();

    $response = $this->postJson("/api/voice-client/v1/polls/{$poll->id}/vote", ['option_id' => $option->id], $this->headers)->assertCreated();

    expect($response->json('my_vote_option_id'))->toBe($option->id)
        ->and($response->json('total_votes'))->toBe(1)
        ->and($response->json('options.0.percent'))->toEqual(100);
    expect(PollVote::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('refuses a second vote and keeps the first (one vote per user, no changing)', function () {
    $poll = Poll::factory()->open()->withOptions(2)->create(['event_id' => $this->event->id]);
    [$a, $b] = $poll->options()->orderBy('sort')->get()->all();

    $this->postJson("/api/voice-client/v1/polls/{$poll->id}/vote", ['option_id' => $a->id], $this->headers)->assertCreated();
    $this->postJson("/api/voice-client/v1/polls/{$poll->id}/vote", ['option_id' => $b->id], $this->headers)
        ->assertStatus(422)->assertJsonPath('code', 'already_voted');

    expect(PollVote::query()->where('user_id', $this->user->id)->pluck('poll_option_id')->all())->toBe([$a->id]);
});

it('refuses a vote on a closed, draft or expired poll', function (string $state) {
    $poll = match ($state) {
        'closed' => Poll::factory()->closed()->withOptions(2)->create(['event_id' => $this->event->id]),
        'draft' => Poll::factory()->withOptions(2)->create(['event_id' => $this->event->id]),
        'expired' => Poll::factory()->open()->withOptions(2)->create(['event_id' => $this->event->id, 'closes_at' => now()->subSecond()]),
    };

    $this->postJson("/api/voice-client/v1/polls/{$poll->id}/vote", ['option_id' => $poll->options()->firstOrFail()->id], $this->headers)
        ->assertStatus(422)->assertJsonPath('code', 'not_open');
    expect(PollVote::query()->count())->toBe(0);
})->with(['closed', 'draft', 'expired']);

it('does not accept an option of another poll or a malformed option id', function () {
    $poll = Poll::factory()->open()->withOptions(2)->create(['event_id' => $this->event->id]);
    $foreign = Poll::factory()->open()->withOptions(2)->create(['event_id' => $this->event->id]);

    $this->postJson("/api/voice-client/v1/polls/{$poll->id}/vote", ['option_id' => $foreign->options()->firstOrFail()->id], $this->headers)->assertNotFound();
    $this->postJson("/api/voice-client/v1/polls/{$poll->id}/vote", ['option_id' => 'abc'], $this->headers)->assertStatus(422)->assertJsonValidationErrors('option_id');
    $this->postJson("/api/voice-client/v1/polls/{$poll->id}/vote", [], $this->headers)->assertStatus(422);
    expect(PollVote::query()->count())->toBe(0);
});

it('returns validation errors as JSON even without an Accept header', function () {
    $poll = Poll::factory()->open()->withOptions(2)->create(['event_id' => $this->event->id]);

    $this->post("/api/voice-client/v1/polls/{$poll->id}/vote", [], $this->headers)->assertStatus(422)->assertJsonValidationErrors('option_id');
});

it('applies the same rules to an MVP poll', function () {
    $poll = Poll::factory()->open()->mvp()->withOptions(2)->create(['event_id' => $this->event->id]);
    $optionId = $poll->options()->firstOrFail()->id;

    $response = $this->postJson("/api/voice-client/v1/polls/{$poll->id}/vote", ['option_id' => $optionId], $this->headers)->assertCreated();

    expect($response->json('kind'))->toBe('mvp');
    $this->postJson("/api/voice-client/v1/polls/{$poll->id}/vote", ['option_id' => $optionId], $this->headers)->assertStatus(422);
});
