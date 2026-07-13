<?php

namespace App\Modules\Identity\Actions;

use App\Models\User;

class UpsertUserFromDiscord
{
    public function handle(string $discordId, string $username, ?string $avatarUrl, ?string $email): User
    {
        return User::updateOrCreate(
            ['discord_id' => $discordId],
            ['name' => $username, 'avatar_url' => $avatarUrl, 'email' => $email],
        );
    }
}
