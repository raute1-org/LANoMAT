<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http\Features;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Voting\Actions\CastVote;
use App\Modules\Voting\Exceptions\VotingException;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollOption;
use App\Modules\Voting\Models\PollVote;
use App\Modules\Voting\Support\PollResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Native client: polls of an event. Same visibility as the browser (every poll of a non-draft
 * event, results always visible) and the same vote rules, because voting goes through CastVote.
 */
class PollsApiController extends NativeFeatureController
{
    public function index(Request $request, Event $event): JsonResponse
    {
        $user = $this->user($request);
        $this->requireVisible($event);

        $polls = Poll::query()
            ->where('event_id', $event->id)
            ->withCount('votes')
            ->orderByDesc('created_at')
            ->get();

        $myVotes = PollVote::query()
            ->whereIn('poll_id', $polls->modelKeys())
            ->where('user_id', $user->id)
            ->pluck('poll_option_id', 'poll_id');

        return response()->json([
            'event_slug' => $event->slug,
            'polls' => $polls->map(fn (Poll $poll): array => [
                'id' => $poll->id,
                'question' => $poll->question,
                'kind' => $poll->kind->value,
                'status' => $poll->status->value,
                'is_open' => $poll->isOpenNow(),
                'closes_at' => $poll->closes_at?->toIso8601String(),
                'total_votes' => (int) $poll->votes_count,
                'my_vote_option_id' => $myVotes->get($poll->id),
            ])->all(),
        ]);
    }

    public function show(Request $request, Poll $poll): JsonResponse
    {
        $user = $this->user($request);
        $event = $this->eventOf($poll);

        return response()->json($this->detail($poll, $event, $user));
    }

    public function vote(Request $request, Poll $poll, CastVote $action): JsonResponse
    {
        $user = $this->user($request);
        $event = $this->eventOf($poll);

        $this->authorizeFor($user, 'vote', $poll);

        $request->validate(['option_id' => ['required', 'integer']]);

        // Scoped to the poll: an option of another poll is simply not found (browser parity).
        $option = PollOption::query()->where('poll_id', $poll->id)->whereKey($request->integer('option_id'))->firstOrFail();

        try {
            $action->handle($poll, $user, $option);
        } catch (VotingException $exception) {
            return $this->domainError($exception->translationKey);
        }

        return response()->json($this->detail($poll->refresh(), $event, $user), 201);
    }

    private function eventOf(Poll $poll): Event
    {
        return $this->requireVisible($poll->event()->first());
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Poll $poll, Event $event, User $user): array
    {
        $results = PollResults::for($poll);
        $myVote = $poll->votes()->where('user_id', $user->id)->first();

        return [
            'id' => $poll->id,
            'event_slug' => $event->slug,
            'question' => $results['question'],
            'kind' => $poll->kind->value,
            'status' => $poll->status->value,
            'is_open' => $poll->isOpenNow(),
            'closes_at' => $poll->closes_at?->toIso8601String(),
            'total_votes' => $results['totalVotes'],
            'options' => $results['options'],
            'my_vote_option_id' => $myVote?->poll_option_id,
        ];
    }
}
