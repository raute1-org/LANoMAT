<?php

declare(strict_types=1);

namespace App\Modules\Friends\Support;

use App\Models\User;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Models\UserBlock;

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

    /**
     * Everybody the user has blocked or who has blocked the user. Used where a block must hide
     * people from each other without telling which side blocked.
     *
     * @return list<int>
     */
    public function blockedUserIdsEitherWay(User $user): array
    {
        $blocks = UserBlock::query()
            ->where('blocker_id', $user->id)
            ->orWhere('blocked_id', $user->id)
            ->get(['blocker_id', 'blocked_id']);

        return array_values(array_unique(
            $blocks->map(fn (UserBlock $block): int => $block->blocker_id === $user->id ? $block->blocked_id : $block->blocker_id)->all(),
        ));
    }
}
