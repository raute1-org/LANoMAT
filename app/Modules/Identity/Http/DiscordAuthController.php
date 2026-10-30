<?php

namespace App\Modules\Identity\Http;

use App\Models\User;
use App\Modules\Identity\Actions\UpsertUserFromDiscord;
use App\Modules\Identity\Contracts\GuildMembershipChecker;
use App\Modules\Identity\Exceptions\GuildMembershipUnavailable;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteTwoUser;
use LogicException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class DiscordAuthController
{
    /** Extra OAuth scope needed for the guild membership check (login gate). */
    private const string GUILD_MEMBERS_SCOPE = 'guilds.members.read';

    public function redirect(): SymfonyRedirectResponse
    {
        $driver = Socialite::driver('discord');

        if ($this->loginGuildId() !== null) {
            if (! $driver instanceof AbstractProvider) {
                throw new LogicException('The Discord Socialite driver must be an OAuth2 provider.');
            }

            // scopes() merges with the provider defaults (identify, email).
            $driver->scopes([self::GUILD_MEMBERS_SCOPE]);
        }

        return $driver->redirect();
    }

    public function callback(Request $request, UpsertUserFromDiscord $upsert, GuildMembershipChecker $membership): RedirectResponse
    {
        if ($request->has('error')) {
            return redirect()->route('login')->with('status', 'Die Anmeldung mit Discord wurde abgebrochen.');
        }

        try {
            $discordUser = Socialite::driver('discord')->user();
        } catch (InvalidStateException|GuzzleException $e) {
            return redirect()->route('login')->with('status', 'Die Discord-Anmeldung ist abgelaufen. Bitte versuche es erneut.');
        }

        $discordId = (string) $discordUser->getId();

        // Login gate: decided BEFORE any user is upserted or logged in.
        if (($guildId = $this->loginGuildId()) !== null && ! $this->isGuildCheckExempt($discordId)) {
            $denial = $this->guildGateDenial($discordUser, $guildId, $membership);

            if ($denial !== null) {
                return $denial;
            }
        }

        $user = $upsert->handle(
            discordId: $discordId,
            username: $discordUser->getNickname() ?? $discordUser->getName() ?? 'Unknown',
            avatarUrl: $discordUser->getAvatar(),
            email: $discordUser->getEmail(),
        );

        Auth::login($user, remember: true);

        return redirect()->intended('/');
    }

    /**
     * Returns the redirect to deny the login, or null when the user may proceed.
     * Fails closed: an unknown membership answer (or a missing access token)
     * is a denial, never a login.
     */
    private function guildGateDenial(SocialiteUserContract $discordUser, string $guildId, GuildMembershipChecker $membership): ?RedirectResponse
    {
        try {
            $accessToken = $discordUser instanceof SocialiteTwoUser ? $discordUser->token : null;

            if ($accessToken === null || $accessToken === '') {
                throw GuildMembershipUnavailable::because('no OAuth access token on the Discord user');
            }

            $isMember = $membership->isMember($accessToken, $guildId);
        } catch (GuildMembershipUnavailable $e) {
            Log::warning('Discord login denied: guild membership check unavailable.', [
                'guild_id' => $guildId,
                'reason' => $e->getMessage(),
            ]);

            return redirect()->route('login')->with('status', 'Die Mitgliedschaft im LAN-Discord-Server konnte gerade nicht geprüft werden. Bitte versuche es in einer Minute erneut.');
        }

        if ($isMember) {
            return null;
        }

        return redirect()->route('login')->with('status', 'Zugang nur für Mitglieder des LAN-Discord-Servers. Tritt dem Server bei und versuche es erneut.');
    }

    /** The guild the login gate checks, or null when no restriction is configured. */
    private function loginGuildId(): ?string
    {
        $guildId = config('services.discord.login_guild_id');

        return filled($guildId) ? (string) $guildId : null;
    }

    private function isGuildCheckExempt(string $discordId): bool
    {
        return User::firstWhere('discord_id', $discordId)?->isAdmin() === true;
    }
}
