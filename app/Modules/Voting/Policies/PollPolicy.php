<?php

namespace App\Modules\Voting\Policies;

use App\Models\User;
use App\Modules\Voting\Actions\CastVote;
use App\Modules\Voting\Models\Poll;

class PollPolicy
{
    /**
     * Poll results are public — anyone (including guests, handled by the
     * calling controller) may view them, mirroring ScheduleItemPolicy.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Poll $poll): bool
    {
        return true;
    }

    /**
     * Any authenticated user may attempt to vote — the open/closed state
     * and one-vote-per-user guard are enforced in {@see CastVote}
     * itself, not here.
     */
    public function vote(User $user, Poll $poll): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, Poll $poll): bool
    {
        return $user->isOrga();
    }

    public function open(User $user, Poll $poll): bool
    {
        return $user->isOrga();
    }

    public function close(User $user, Poll $poll): bool
    {
        return $user->isOrga();
    }
}
