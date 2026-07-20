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
     */
    public function refresh(LinkedAccount $account): LinkedAccountData
    {
        $driver = Socialite::driver('twitch');

        if (! $driver instanceof AbstractProvider) {
            throw IdentityException::tokenRefreshFailed($this->provider());
        }

        $token = $driver->refreshToken((string) $account->refresh_token);

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
