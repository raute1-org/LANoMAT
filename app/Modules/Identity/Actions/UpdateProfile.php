<?php

namespace App\Modules\Identity\Actions;

use App\Models\User;

class UpdateProfile
{
    /**
     * Update the given user's profile with validated, whitelisted data.
     *
     * Resets email verification when the email actually changes, matching
     * the starter kit's prior inline behaviour.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(User $user, array $data): User
    {
        $user->fill($data);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return $user;
    }
}
