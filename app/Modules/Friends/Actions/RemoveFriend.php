<?php

declare(strict_types=1);

namespace App\Modules\Friends\Actions;

use App\Models\User;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;
use Illuminate\Support\Facades\Gate;

class RemoveFriend
{
    public function handle(User $actor, User $other): void
    {
        $friendship = Friendship::query()
            ->betweenUsers($actor->id, $other->id)
            ->where('status', FriendshipStatus::Accepted)
            ->first();

        if ($friendship === null) {
            return;
        }

        Gate::forUser($actor)->authorize('remove', $friendship);

        $friendship->delete();
    }
}
