<?php

declare(strict_types=1);

use App\Modules\Identity\Support\LinkedAccountData;
use Laravel\Socialite\Two\User as SocialiteTwoUser;
use SocialiteProviders\Manager\OAuth2\User as SocialiteProvidersOAuth2User;

/**
 * Steam's socialiteproviders driver returns a Two\User subclass whose
 * approvedScopes is never set (null) and which carries no token. The mapping
 * must still produce a valid LinkedAccountData with empty scopes instead of
 * a TypeError on the typed `array $scopes` constructor parameter.
 */
it('maps a Steam-style OAuth2 user with null approvedScopes to empty scopes', function () {
    $user = (new SocialiteProvidersOAuth2User)->map([
        'id' => '76561198000000000',
        'nickname' => 'Tester',
    ]);
    $user->setApprovedScopes(null);

    $data = LinkedAccountData::fromSocialite($user);

    expect($data->provider_user_id)->toBe('76561198000000000')
        ->and($data->nickname)->toBe('Tester')
        ->and($data->scopes)->toBe([])
        ->and($data->access_token)->toBeNull()
        ->and($data->refresh_token)->toBeNull()
        ->and($data->token_expires_at)->toBeNull();
});

it('maps a plain Socialite OAuth2 user with approved scopes and token', function () {
    $user = (new SocialiteTwoUser)->map([
        'id' => '42',
        'nickname' => 'Streamer',
    ]);
    $user->setToken('access-123')
        ->setRefreshToken('refresh-456')
        ->setExpiresIn(3600)
        ->setApprovedScopes(['user:read:email', 'channel:read']);

    $data = LinkedAccountData::fromSocialite($user);

    expect($data->provider_user_id)->toBe('42')
        ->and($data->access_token)->toBe('access-123')
        ->and($data->refresh_token)->toBe('refresh-456')
        ->and($data->token_expires_at)->not->toBeNull()
        ->and($data->scopes)->toBe(['user:read:email', 'channel:read']);
});
