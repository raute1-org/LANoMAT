<?php

namespace App\Modules\Voting\Actions;

use App\Models\User;
use App\Modules\Voting\Events\PollUpdated;
use App\Modules\Voting\Exceptions\VotingException;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollOption;
use App\Modules\Voting\Models\PollVote;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class CastVote
{
    public function handle(Poll $poll, User $user, PollOption $option): PollVote
    {
        $vote = DB::transaction(function () use ($poll, $user, $option): PollVote {
            // Lock the PARENT poll row first (see RegisterForEvent for the
            // rationale): this serializes all concurrent votes on this poll
            // so the open/closed check below is never racing a concurrent
            // OpenPoll/ClosePoll transition, and the unique-index catch
            // below is the last line of defense against a same-user double
            // vote slipping through a race.
            $lockedPoll = Poll::query()->whereKey($poll->getKey())->lockForUpdate()->firstOrFail();

            if (! $lockedPoll->isOpenNow()) {
                throw VotingException::notOpen();
            }

            if ($option->poll_id !== $lockedPoll->id) {
                throw VotingException::optionNotInPoll();
            }

            try {
                // user_id is intentionally NOT fillable on PollVote (see the
                // model), so it is set here via forceFill() from the
                // trusted, auth()-resolved $user — never from client-supplied
                // input (forceFill bypasses only $fillable, not phpstan's
                // int<0,max> narrowing that a direct property write on
                // $user->id would otherwise trip).
                $vote = new PollVote([
                    'poll_id' => $lockedPoll->id,
                    'poll_option_id' => $option->id,
                ]);
                $vote->forceFill(['user_id' => $user->id]);
                $vote->save();
            } catch (QueryException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e;
                }

                throw VotingException::alreadyVoted();
            }

            return $vote;
        });

        Event::dispatch(new PollUpdated($poll));

        return $vote;
    }
}
