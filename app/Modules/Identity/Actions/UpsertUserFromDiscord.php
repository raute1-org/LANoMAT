<?php

namespace App\Modules\Identity\Actions;

use App\Models\User;

class UpsertUserFromDiscord
{
    /**
     * Create or update a user from Discord OAuth data.
     *
     * Field ownership (M0 whole-branch review):
     * - Discord-owned: `avatar_url` (+ `discord_id`) — refreshed on every login.
     * - User-owned: `name`, `email` — only filled once, on first creation; a
     *   later edit by the user (or a previous login) must survive relogin.
     */
    public function handle(string $discordId, string $username, ?string $avatarUrl, ?string $email): User
    {
        $user = User::firstWhere('discord_id', $discordId);

        if ($user !== null) {
            $user->forceFill(['avatar_url' => $avatarUrl])->save();

            return $user;
        }

        return User::create([
            'discord_id' => $discordId,
            'name' => $username,
            'avatar_url' => $avatarUrl,
            // Email collision (mandatory addition 2): `users.email` is unique.
            // Two Discord accounts can share the same Discord-verified email;
            // rather than let the insert 500 on the unique constraint, drop the
            // email for the newly created account when it's already taken.
            'email' => $email !== null && User::where('email', $email)->exists() ? null : $email,
        ]);
    }
}
