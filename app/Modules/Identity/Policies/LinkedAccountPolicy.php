<?php

declare(strict_types=1);

namespace App\Modules\Identity\Policies;

use App\Models\User;
use App\Modules\Identity\Models\LinkedAccount;

/**
 * A linked account may only be managed by the user it belongs to — never
 * trust a client-supplied user id, the acting user always comes from
 * `auth()->user()` in the controller.
 */
class LinkedAccountPolicy
{
    public function update(User $user, LinkedAccount $account): bool
    {
        return $user->id === $account->user_id;
    }

    public function delete(User $user, LinkedAccount $account): bool
    {
        return $user->id === $account->user_id;
    }
}
