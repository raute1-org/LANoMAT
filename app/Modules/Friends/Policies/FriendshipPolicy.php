<?php

declare(strict_types=1);

namespace App\Modules\Friends\Policies;

use App\Models\User;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;

/**
 * Never trust a client-supplied user id — the acting user always comes
 * from `auth()->user()` in the controller.
 */
class FriendshipPolicy
{
    public function respond(User $user, Friendship $friendship): bool
    {
        return $user->id === $friendship->addressee_id
            && $friendship->status === FriendshipStatus::Pending;
    }

    public function cancel(User $user, Friendship $friendship): bool
    {
        return $user->id === $friendship->requester_id
            && $friendship->status === FriendshipStatus::Pending;
    }

    public function remove(User $user, Friendship $friendship): bool
    {
        return $this->isParticipant($user, $friendship);
    }

    public function view(User $user, Friendship $friendship): bool
    {
        return $this->isParticipant($user, $friendship);
    }

    private function isParticipant(User $user, Friendship $friendship): bool
    {
        return $user->id === $friendship->requester_id || $user->id === $friendship->addressee_id;
    }
}
