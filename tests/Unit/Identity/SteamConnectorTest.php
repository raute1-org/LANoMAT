<?php

declare(strict_types=1);

use App\Modules\Identity\Connectors\SteamConnector;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Exercises the real SteamConnector::ownsApp() against a faked HTTP response
 * (Http::fake(), never a real Steam Web API call) — proving the Steam Web
 * API's GetOwnedGames response is mapped correctly and every failure mode
 * collapses to null (unknown) rather than throwing, since this sits behind
 * an advisory-only hint that must never break enrollment (see M9 task 9.7
 * and GameOwnershipHint).
 */
it('reports Owned when GetOwnedGames includes the app id', function () {
    config(['services.steam.client_secret' => 'fake-api-key']);
    Http::fake([
        'api.steampowered.com/*' => Http::response([
            'response' => ['games' => [['appid' => 730, 'playtime_forever' => 100]]],
        ]),
    ]);
    $account = LinkedAccount::factory()->create([
        'provider' => LinkedAccountProvider::Steam,
        'provider_user_id' => '76561198000000000',
    ]);

    expect(app(SteamConnector::class)->ownsApp($account, '730'))->toBeTrue();
});

it('reports NotOwned when GetOwnedGames does not include the app id', function () {
    config(['services.steam.client_secret' => 'fake-api-key']);
    Http::fake([
        'api.steampowered.com/*' => Http::response([
            'response' => ['games' => [['appid' => 440, 'playtime_forever' => 5]]],
        ]),
    ]);
    $account = LinkedAccount::factory()->create([
        'provider' => LinkedAccountProvider::Steam,
        'provider_user_id' => '76561198000000000',
    ]);

    expect(app(SteamConnector::class)->ownsApp($account, '730'))->toBeFalse();
});

it('reports Unknown when the profile is private (empty response, no games key)', function () {
    config(['services.steam.client_secret' => 'fake-api-key']);
    Http::fake([
        'api.steampowered.com/*' => Http::response(['response' => []]),
    ]);
    $account = LinkedAccount::factory()->create([
        'provider' => LinkedAccountProvider::Steam,
        'provider_user_id' => '76561198000000000',
    ]);

    expect(app(SteamConnector::class)->ownsApp($account, '730'))->toBeNull();
});

it('reports Unknown when the API call fails', function () {
    config(['services.steam.client_secret' => 'fake-api-key']);
    Http::fake([
        'api.steampowered.com/*' => Http::response('', 500),
    ]);
    $account = LinkedAccount::factory()->create([
        'provider' => LinkedAccountProvider::Steam,
        'provider_user_id' => '76561198000000000',
    ]);

    expect(app(SteamConnector::class)->ownsApp($account, '730'))->toBeNull();
});

it('reports Unknown when no Steam Web API key is configured', function () {
    config(['services.steam.client_secret' => null]);
    Http::fake(); // any real call here would fail the test
    $account = LinkedAccount::factory()->create([
        'provider' => LinkedAccountProvider::Steam,
        'provider_user_id' => '76561198000000000',
    ]);

    expect(app(SteamConnector::class)->ownsApp($account, '730'))->toBeNull();
    Http::assertNothingSent();
});

it('reports Unknown when the HTTP call throws (connection error)', function () {
    config(['services.steam.client_secret' => 'fake-api-key']);
    Http::fake(function (): never {
        throw new ConnectionException('connection refused');
    });
    $account = LinkedAccount::factory()->create([
        'provider' => LinkedAccountProvider::Steam,
        'provider_user_id' => '76561198000000000',
    ]);

    expect(app(SteamConnector::class)->ownsApp($account, '730'))->toBeNull();
});

it('returns friend SteamIDs from GetFriendList', function () {
    config(['services.steam.client_secret' => 'fake-api-key']);
    Http::fake([
        'api.steampowered.com/*' => Http::response([
            'friendslist' => ['friends' => [
                ['steamid' => '111', 'relationship' => 'friend', 'friend_since' => 1],
                ['steamid' => '222', 'relationship' => 'friend', 'friend_since' => 2],
            ]],
        ]),
    ]);
    $account = LinkedAccount::factory()->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => '999']);

    expect(app(SteamConnector::class)->friendProviderIds($account))->toBe(['111', '222']);
});

it('returns [] on a private profile (401), missing key, or malformed response', function () {
    $account = LinkedAccount::factory()->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => '999']);

    config(['services.steam.client_secret' => '']);           // missing key
    expect(app(SteamConnector::class)->friendProviderIds($account))->toBe([]);

    config(['services.steam.client_secret' => 'fake-api-key']);
    Http::fake(['api.steampowered.com/*' => Http::response([], 401)]); // private
    expect(app(SteamConnector::class)->friendProviderIds($account))->toBe([]);

    Http::fake(['api.steampowered.com/*' => Http::response(['friendslist' => []])]); // malformed
    expect(app(SteamConnector::class)->friendProviderIds($account))->toBe([]);
});

it('returns [] when the HTTP call throws (connection error)', function () {
    config(['services.steam.client_secret' => 'fake-api-key']);
    Http::fake(function (): never {
        throw new ConnectionException('connection refused');
    });
    $account = LinkedAccount::factory()->create([
        'provider' => LinkedAccountProvider::Steam,
        'provider_user_id' => '76561198000000000',
    ]);

    expect(app(SteamConnector::class)->friendProviderIds($account))->toBe([]);
});
