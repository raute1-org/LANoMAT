<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Actions\UpsertUserFromDiscord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class DiscordAuthController
{
    public function redirect(): SymfonyRedirectResponse
    {
        return Socialite::driver('discord')->redirect();
    }

    public function callback(UpsertUserFromDiscord $upsert): RedirectResponse
    {
        $discordUser = Socialite::driver('discord')->user();

        $user = $upsert->handle(
            discordId: (string) $discordUser->getId(),
            username: $discordUser->getNickname() ?? $discordUser->getName() ?? 'Unknown',
            avatarUrl: $discordUser->getAvatar(),
            email: $discordUser->getEmail(),
        );

        Auth::login($user, remember: true);

        return redirect('/');
    }
}
