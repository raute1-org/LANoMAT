<?php

namespace App\Modules\Identity\Http;

use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController
{
    /**
     * Show a user's public profile. Exposes only public-safe fields —
     * never email, discord_id, or role.
     */
    public function show(User $user): Response
    {
        return Inertia::render('Profile/Show', [
            'profile' => [
                'name' => $user->name,
                'avatarUrl' => $user->avatar_url,
                'bio' => $user->bio,
                'steamUrl' => $user->steam_url,
                'profileColor' => $user->profile_color,
            ],
            'labels' => trans('profile.public'),
        ]);
    }
}
