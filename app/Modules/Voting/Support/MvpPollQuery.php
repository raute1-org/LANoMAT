<?php

namespace App\Modules\Voting\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Voting\Enums\PollKind;
use App\Modules\Voting\Enums\PollStatus;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollOption;

/**
 * Read-only lookup for the event's "player of the evening" poll — consumed
 * by the (Task 13) badge-award/beamer-reveal/recap flow to find the closed
 * MVP poll and its winning option without duplicating the query.
 */
class MvpPollQuery
{
    public static function closedFor(Event $event): ?Poll
    {
        return Poll::query()
            ->where('event_id', $event->id)
            ->where('kind', PollKind::Mvp)
            ->where('status', PollStatus::Closed)
            ->first();
    }

    /**
     * The option with the most votes; ties are broken deterministically by
     * the earliest `sort`, then the earliest `id`. Returns `null` when the
     * poll has no options, or when it has options but zero votes were cast
     * — a poll nobody voted in has no winner, not a default "option 0".
     */
    public static function winner(Poll $poll): ?PollOption
    {
        $top = $poll->options()
            ->withCount('votes')
            ->orderByDesc('votes_count')
            ->orderBy('sort')
            ->orderBy('id')
            ->first();

        if ($top === null || $top->votes_count === 0) {
            return null;
        }

        return $top;
    }
}
