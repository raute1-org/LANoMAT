<?php

namespace App\Modules\Voting\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Voting\Actions\CastVote;
use App\Modules\Voting\Exceptions\VotingException;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollOption;
use App\Modules\Voting\Support\PollResults;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PollPageController extends Controller
{
    use AuthorizesRequests;
    use ResolvesAuthenticatedUser;

    /**
     * Every poll belonging to the event, newest first, with a lean
     * question/status/vote-count summary the index list can render without
     * pulling in the full per-option tally (that's what `show` is for).
     */
    public function index(Event $event): Response
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $polls = Poll::query()
            ->where('event_id', $event->id)
            ->withCount('votes')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Polls/Index', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'polls' => $polls->map(fn (Poll $poll): array => [
                'id' => $poll->id,
                'question' => $poll->question,
                'status' => $poll->status->value,
                'statusLabel' => $poll->status->label(),
                'totalVotes' => (int) $poll->votes_count,
            ])->all(),
            'labels' => trans('polls.page'),
        ]);
    }

    /**
     * The results view for a single poll: the {@see PollResults} tally
     * projection (shared with the live broadcast payload, so both always
     * agree), the viewer's own existing vote (if any, so the UI can disable
     * re-voting), and the poll's live open/closed state.
     */
    public function show(Request $request, Poll $poll): Response
    {
        $poll->load('event');
        $event = $poll->event;
        abort_if($event === null, 500, 'Poll has no associated event.');
        abort_unless($event->isPubliclyVisible(), 404);

        $user = $request->user();
        $myVote = $user === null
            ? null
            : $poll->votes()->where('user_id', $user->id)->first();

        return Inertia::render('Polls/Show', [
            'event' => ['id' => $event->id, 'name' => $event->name, 'slug' => $event->slug],
            'poll' => [...PollResults::for($poll), 'isOpen' => $poll->isOpenNow()],
            'myVoteOptionId' => $myVote?->poll_option_id,
            'labels' => trans('polls.page'),
        ]);
    }

    public function vote(Request $request, Poll $poll, CastVote $action): RedirectResponse
    {
        $this->authorize('vote', $poll);

        $user = $this->authUser($request);

        $option = PollOption::query()
            ->where('poll_id', $poll->id)
            ->findOrFail($request->integer('option_id'));

        try {
            $action->handle($poll, $user, $option);
        } catch (VotingException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($exception->translationKey)]);

            return back();
        }

        return back();
    }
}
