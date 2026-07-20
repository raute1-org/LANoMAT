<?php

declare(strict_types=1);

namespace App\Modules\Friends\Actions;

use App\Models\User;
use App\Modules\Friends\Models\Friendship;
use Illuminate\Support\Facades\Gate;

class CancelFriendRequest
{
    public function handle(User $actor, Friendship $friendship): void
    {
        Gate::forUser($actor)->authorize('cancel', $friendship);

        $friendship->delete();
    }
}
