<?php

declare(strict_types=1);

namespace App\Modules\Identity\Connectors;

use App\Modules\Identity\Contracts\LinkedAccountConnector;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Support\LinkedAccountData;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Throwable;

/**
 * Twitch OAuth2 connector — the only linkable provider with a real token
 * lifecycle (see {@see LinkedAccountProvider::hasTokenLifecycle()}). Backed
 * by `socialiteproviders/twitch`, registered in
 * {@see AppServiceProvider::configureSocialite()}.
 */
class TwitchConnector implements LinkedAccountConnector
{
    public function provider(): LinkedAccountProvider
    {
        return LinkedAccountProvider::Twitch;
    }

    public function redirectUrl(): string
    {
        return Socialite::driver('twitch')->redirect()->getTargetUrl();
    }

    public function resolveCallback(): LinkedAccountData
    {
        return LinkedAccountData::fromSocialite(Socialite::driver('twitch')->user());
    }

    /**
     * Exchanges the account's stored refresh token for a fresh access token.
     * The `provider_user_id` and `nickname` are carried over unchanged since
     * Twitch's refresh response only contains token data, not profile data.
     *
     * The actual HTTP exchange (`refreshToken()`) is wrapped: a revoked or
     * expired refresh token makes Twitch respond with 400/401, which
     * Socialite surfaces as a raw `GuzzleHttp\Exception\RequestException`.
     * That must not leak past this connector — {@see
     * \App\Modules\Identity\Actions\RefreshLinkedAccountToken} only catches
     * {@see IdentityException} to decide whether to flag `needs_reauth` and
     * notify the user, so any unwrapped exception here would crash the
     * refresh sweep instead of triggering that path.
     */
    public function refresh(LinkedAccount $account): LinkedAccountData
    {
        $driver = Socialite::driver('twitch');

        if (! $driver instanceof AbstractProvider) {
            throw IdentityException::tokenRefreshFailed($this->provider());
        }

        try {
            $token = $driver->refreshToken((string) $account->refresh_token);
        } catch (Throwable) {
            throw IdentityException::tokenRefreshFailed($this->provider());
        }

        return new LinkedAccountData(
            provider_user_id: $account->provider_user_id,
            nickname: $account->nickname,
            access_token: $token->token,
            refresh_token: $token->refreshToken,
            token_expires_at: CarbonImmutable::now()->addSeconds($token->expiresIn),
            scopes: $token->approvedScopes,
        );
    }
}
