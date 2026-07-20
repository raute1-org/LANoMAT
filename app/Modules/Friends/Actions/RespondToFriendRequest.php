<?php

declare(strict_types=1);

namespace App\Modules\Friends\Actions;

use App\Models\User;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Notifications\FriendRequestAccepted;
use Illuminate\Support\Facades\Gate;

class RespondToFriendRequest
{
    public function handle(User $actor, Friendship $friendship, bool $accept): void
    {
        Gate::forUser($actor)->authorize('respond', $friendship);

        if ($accept) {
            $friendship->status = FriendshipStatus::Accepted;
            $friendship->save();

            // requester_id is a non-nullable FK (see the Friendship model),
            // so this always resolves; firstOrFail() only satisfies static
            // analysis, matching Friendship::otherUser()'s reasoning.
            $friendship->requester()->firstOrFail()->notify(new FriendRequestAccepted($actor));

            return;
        }

        $friendship->delete();
    }
}
