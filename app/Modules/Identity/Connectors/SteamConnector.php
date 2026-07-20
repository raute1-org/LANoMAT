<?php

declare(strict_types=1);

namespace App\Modules\Identity\Connectors;

use App\Modules\Identity\Contracts\LinkedAccountConnector;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Support\LinkedAccountData;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

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

    /**
     * Best-effort ownership check via Steam's Web API `GetOwnedGames`
     * endpoint. This is inherently limited: it only works for accounts with
     * a public "game details" privacy setting, and needs the same Web API
     * key already configured for Socialite (`services.steam.client_secret`,
     * see AppServiceProvider::configureSocialite()). ANY failure — missing
     * key, private profile, network error, malformed response — resolves to
     * `null` (unknown) rather than throwing: this method sits behind an
     * advisory-only hint (see LinkedAccountConnector::ownsApp()) and must
     * never be able to propagate an exception into a caller that expects it
     * to be safe to call unconditionally.
     */
    public function ownsApp(LinkedAccount $account, string $appId): ?bool
    {
        $apiKey = config('services.steam.client_secret');

        if (! is_string($apiKey) || $apiKey === '') {
            return null;
        }

        try {
            $response = Http::get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v1/', [
                'key' => $apiKey,
                'steamid' => $account->provider_user_id,
                'format' => 'json',
                'include_appinfo' => 0,
                'include_played_free_games' => 1,
            ]);

            if (! $response->successful()) {
                return null;
            }

            /** @var mixed $games */
            $games = $response->json('response.games');

            // A private "game details" profile makes Steam return a bare
            // `{"response": {}}` with no `games` key at all — indistinguishable
            // from "the API call itself failed" from the caller's perspective,
            // so both collapse to unknown.
            if (! is_array($games)) {
                return null;
            }

            foreach ($games as $game) {
                if (is_array($game) && (string) ($game['appid'] ?? '') === $appId) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return null;
        }
    }
}
