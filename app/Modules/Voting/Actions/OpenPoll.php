<?php

namespace App\Modules\Voting\Actions;

use App\Modules\Voting\Enums\PollStatus;
use App\Modules\Voting\Exceptions\VotingException;
use App\Modules\Voting\Models\Poll;
use Illuminate\Support\Facades\DB;

/**
 * Transitions a poll Draft -> Open. `status` is deliberately not
 * mass-assignable on {@see Poll} (a privilege/state field), so it is set
 * here via explicit property assignment after the transition is validated.
 */
class OpenPoll
{
    public function handle(Poll $poll): Poll
    {
        return DB::transaction(function () use ($poll): Poll {
            $poll = Poll::query()->whereKey($poll->getKey())->lockForUpdate()->firstOrFail();

            if ($poll->status !== PollStatus::Draft) {
                throw VotingException::alreadyOpen();
            }

            $poll->status = PollStatus::Open;
            $poll->save();

            return $poll;
        });
    }
}
