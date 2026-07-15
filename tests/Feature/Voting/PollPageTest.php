<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Voting\Actions\CastVote;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollVote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('renders the poll page with german labels and option counts for a public event', function () {
    $event = Event::factory()->registration()->create();
    $poll = Poll::factory()->for($event)->open()->withOptions(2)->create(['question' => 'Welches Spiel als nächstes?']);
    $option = $poll->options->first();
    app(CastVote::class)->handle($poll, User::factory()->create(), $option);

    $this->get("/polls/{$poll->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Polls/Show')
            ->where('labels.title', 'Abstimmung')
            ->where('poll.question', 'Welches Spiel als nächstes?')
            ->where('poll.totalVotes', 1)
            ->has('poll.options', 2)
        );
});

it('returns 404 for a draft event', function () {
    $event = Event::factory()->draft()->create();
    $poll = Poll::factory()->for($event)->open()->withOptions(2)->create();

    $this->get("/polls/{$poll->id}")->assertNotFound();
});

it('lists the events polls on the index page', function () {
    $event = Event::factory()->registration()->create();
    Poll::factory()->for($event)->open()->withOptions(2)->create(['question' => 'Welches Spiel als nächstes?']);

    $this->get("/events/{$event->slug}/polls")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Polls/Index')
            ->where('labels.title', 'Abstimmung')
            ->has('polls', 1)
            ->where('polls.0.question', 'Welches Spiel als nächstes?')
        );
});

it('casts a vote for the authenticated user and redirects back', function () {
    $event = Event::factory()->registration()->create();
    $poll = Poll::factory()->for($event)->open()->withOptions(2)->create();
    $option = $poll->options->first();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from("/polls/{$poll->id}")
        ->post("/polls/{$poll->id}/vote", ['option_id' => $option->id])
        ->assertRedirect("/polls/{$poll->id}");

    expect(PollVote::where('poll_id', $poll->id)->where('user_id', $user->id)->where('poll_option_id', $option->id)->exists())->toBeTrue();
});

it('redirects back with a german already-voted flash on a second vote by the same user', function () {
    $event = Event::factory()->registration()->create();
    $poll = Poll::factory()->for($event)->open()->withOptions(2)->create();
    [$optionA, $optionB] = $poll->options;
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/polls/{$poll->id}/vote", ['option_id' => $optionA->id])
        ->assertRedirect();

    $response = $this->actingAs($user)
        ->from("/polls/{$poll->id}")
        ->post("/polls/{$poll->id}/vote", ['option_id' => $optionB->id]);

    $response->assertRedirect("/polls/{$poll->id}");
    $response->assertInertiaFlash('toast', [
        'type' => 'error',
        'message' => __('polls.errors.already_voted'),
    ]);

    expect(PollVote::where('poll_id', $poll->id)->where('user_id', $user->id)->count())->toBe(1);
});

it('never trusts a client-supplied user id when casting a vote', function () {
    $event = Event::factory()->registration()->create();
    $poll = Poll::factory()->for($event)->open()->withOptions(2)->create();
    $option = $poll->options->first();
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($user)
        ->post("/polls/{$poll->id}/vote", ['option_id' => $option->id, 'user_id' => $otherUser->id])
        ->assertRedirect();

    $vote = PollVote::where('poll_id', $poll->id)->first();

    expect($vote->user_id)->toBe($user->id);
});
