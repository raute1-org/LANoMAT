<?php

declare(strict_types=1);

namespace App\Modules\Friends\Support;

use App\Models\User;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;

/**
 * Read-only helpers over the Friends module's data, shared by actions,
 * policies, and controllers so friendship/block queries live in one place.
 */
class FriendService
{
    public function areFriends(User $a, User $b): bool
    {
        return Friendship::query()
            ->betweenUsers($a->id, $b->id)
            ->where('status', FriendshipStatus::Accepted)
            ->exists();
    }

    public function pendingBetween(User $a, User $b): ?Friendship
    {
        return Friendship::query()
            ->betweenUsers($a->id, $b->id)
            ->where('status', FriendshipStatus::Pending)
            ->first();
    }

    public function blockedEitherWay(User $a, User $b): bool
    {
        return $a->hasBlocked($b) || $b->hasBlocked($a);
    }

    /** @return array<int> */
    public function friendUserIds(User $user): array
    {
        return $user->acceptedFriends()->pluck('id')->all();
    }
}
