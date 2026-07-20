<?php

declare(strict_types=1);

use App\Modules\Identity\Connectors\TwitchConnector;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\LinkedAccount;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Twitch\Provider as TwitchProvider;

uses(RefreshDatabase::class);

/**
 * Exercises the real connector against a mocked Socialite driver — no Fake,
 * no real HTTP — proving the `refreshToken()` failure path is wrapped into
 * a domain exception rather than leaking Guzzle's raw exception (see
 * TwitchConnector::refresh() docblock and the M9 task 9.4 review finding).
 */
it('wraps a Socialite/Guzzle refresh-token failure into an IdentityException', function () {
    $account = LinkedAccount::factory()->create([
        'provider' => LinkedAccountProvider::Twitch,
        'refresh_token' => 'stale-refresh-token',
    ]);

    $driver = Mockery::mock(TwitchProvider::class);
    $driver->shouldReceive('refreshToken')
        ->once()
        ->with('stale-refresh-token')
        ->andThrow(new RequestException(
            'Client error: `POST https://id.twitch.tv/oauth2/token` resulted in a `400 Bad Request` response',
            new Request('POST', 'https://id.twitch.tv/oauth2/token'),
        ));

    Socialite::shouldReceive('driver')->with('twitch')->andReturn($driver);

    expect(fn () => app(TwitchConnector::class)->refresh($account))
        ->toThrow(IdentityException::class);
});

it('reports ownership as always unknown, since Twitch has no ownership concept', function () {
    $account = LinkedAccount::factory()->create(['provider' => LinkedAccountProvider::Twitch]);

    expect(app(TwitchConnector::class)->ownsApp($account, 'irrelevant-app-id'))->toBeNull();
});

it('wraps any other refresh-token throwable the same way', function () {
    $account = LinkedAccount::factory()->create([
        'provider' => LinkedAccountProvider::Twitch,
        'refresh_token' => 'stale-refresh-token',
    ]);

    $driver = Mockery::mock(TwitchProvider::class);
    $driver->shouldReceive('refreshToken')->once()->andThrow(new RuntimeException('connection reset'));

    Socialite::shouldReceive('driver')->with('twitch')->andReturn($driver);

    expect(fn () => app(TwitchConnector::class)->refresh($account))
        ->toThrow(IdentityException::class);
});
