<?php

declare(strict_types=1);

namespace App\Modules\Identity\Policies;

use App\Models\User;

/**
 * Avatars are visible to every logged-in user, like names are. Guests never get one: the avatar
 * routes sit behind the session / bearer authentication, and this ability is checked on top of it.
 */
class AvatarPolicy
{
    public function view(User $viewer, User $subject): bool
    {
        return true;
    }
}
