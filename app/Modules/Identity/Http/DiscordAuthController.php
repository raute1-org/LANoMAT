<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Actions\UpsertUserFromDiscord;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class DiscordAuthController
{
    public function redirect(): SymfonyRedirectResponse
    {
        return Socialite::driver('discord')->redirect();
    }

    public function callback(Request $request, UpsertUserFromDiscord $upsert): RedirectResponse
    {
        if ($request->has('error')) {
            return redirect()->route('login')->with('status', 'Die Anmeldung mit Discord wurde abgebrochen.');
        }

        try {
            $discordUser = Socialite::driver('discord')->user();
        } catch (InvalidStateException|GuzzleException $e) {
            return redirect()->route('login')->with('status', 'Die Discord-Anmeldung ist abgelaufen. Bitte versuche es erneut.');
        }

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
