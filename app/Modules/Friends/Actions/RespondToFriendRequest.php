<?php

declare(strict_types=1);

namespace App\Modules\Friends\Actions;

use App\Models\User;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;
use Illuminate\Support\Facades\Gate;

class RespondToFriendRequest
{
    public function handle(User $actor, Friendship $friendship, bool $accept): void
    {
        Gate::forUser($actor)->authorize('respond', $friendship);

        if ($accept) {
            $friendship->status = FriendshipStatus::Accepted;
            $friendship->save();

            return;
        }

        $friendship->delete();
    }
}
