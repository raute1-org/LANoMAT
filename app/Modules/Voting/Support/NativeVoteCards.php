<?php

declare(strict_types=1);

namespace App\Modules\Voting\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Voting\Enums\PollStatus;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollOption;
use App\Modules\WebVoice\Contracts\VoteCardSource;
use App\Modules\WebVoice\Domain\NowCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The native client's "now" cards for Voting: every open poll of the event the user has not
 * voted in yet, closing soonest first. Voting rights are the poll policy's (`vote`).
 */
class NativeVoteCards implements VoteCardSource
{
    /** A poll closing within this many minutes jumps ahead of other cards of the same tier. */
    private const CLOSING_SOON_MINUTES = 10;

    public function cards(User $user, Event $event): array
    {
        $polls = Poll::query()
            ->where('event_id', $event->id)
            ->where('status', PollStatus::Open->value)
            ->where(fn (Builder $open) => $open->whereNull('closes_at')->orWhere('closes_at', '>=', now()))
            ->whereDoesntHave('votes', fn (Builder $votes) => $votes->where('user_id', $user->id))
            ->with(['options' => fn ($options) => $options->orderBy('sort')->orderBy('id')])
            ->orderByRaw('closes_at asc nulls last')
            ->orderBy('id')
            ->get();

        $cards = [];

        foreach ($polls as $poll) {
            if (Gate::forUser($user)->denies('vote', $poll)) {
                continue;
            }

            $soon = $poll->closes_at !== null && $poll->closes_at->lte(now()->addMinutes(self::CLOSING_SOON_MINUTES));

            $cards[] = new NowCard(
                kind: NowCard::KIND_VOTE,
                id: 'vote:'.$poll->id,
                state: 'open',
                priority: $soon ? 30 : 40,
                title: $poll->question,
                body: trans('native_client.now.vote_open'),
                at: $poll->closes_at,
                data: [
                    'pollId' => $poll->id,
                    'question' => $poll->question,
                    'closesAt' => $poll->closes_at?->toIso8601String(),
                    'options' => $poll->options->map(fn (PollOption $option): array => ['id' => $option->id, 'label' => $option->label])->values()->all(),
                ],
            );
        }

        return $cards;
    }
}
