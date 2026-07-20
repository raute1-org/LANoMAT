<?php

declare(strict_types=1);

namespace App\Modules\Identity\Connectors;

use App\Modules\Identity\Contracts\LinkedAccountConnector;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Support\LinkedAccountData;
use App\Providers\AppServiceProvider;
use Laravel\Socialite\Facades\Socialite;

/**
 * Steam OpenID connector — identity only, no OAuth token lifecycle
 * (see {@see LinkedAccountProvider::hasTokenLifecycle()}). Backed by
 * `socialiteproviders/steam`, registered in
 * {@see AppServiceProvider::configureSocialite()}.
 */
class SteamConnector implements LinkedAccountConnector
{
    public function provider(): LinkedAccountProvider
    {
        return LinkedAccountProvider::Steam;
    }

    public function redirectUrl(): string
    {
        return Socialite::driver('steam')->redirect()->getTargetUrl();
    }

    public function resolveCallback(): LinkedAccountData
    {
        return LinkedAccountData::fromSocialite(Socialite::driver('steam')->user());
    }

    public function refresh(LinkedAccount $account): LinkedAccountData
    {
        throw IdentityException::unsupportedTokenRefresh($this->provider());
    }
}
