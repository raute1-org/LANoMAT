<?php

namespace App\Modules\Identity\Support;

use App\Models\User;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;

/**
 * Read model: which third-party accounts a user has linked.
 */
class LinkedProviders
{
    public static function has(User $user, LinkedAccountProvider $provider): bool
    {
        return LinkedAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider->value)
            ->exists();
    }
}
