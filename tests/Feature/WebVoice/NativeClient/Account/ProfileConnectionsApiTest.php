<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Support\NativeLinkTokens;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

const NATIVE_ACCOUNT_V1 = '/api/voice-client/v1';

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 12:00:00');
    config([
        'services.steam.client_secret' => 'x',
        'services.twitch.client_id' => 'x',
        'services.twitch.client_secret' => 'x',
    ]);
    $this->user = User::factory()->create([
        'name' => 'Ada', 'email' => 'ada@example.test', 'avatar_url' => 'https://cdn.example.test/a.png',
        'bio' => 'hi', 'role' => 'admin', 'discord_id' => '4242',
    ]);
    $this->headers = accountBearer($this->user);
});

describe('authentication', function () {
    it('answers 401 without, with garbage and with revoked tokens on every profile and connections route', function () {
        $routes = [
            ['GET', '/profile'], ['PUT', '/profile'], ['PATCH', '/profile'],
            ['GET', '/connections'], ['POST', '/connections/steam/link-url'], ['DELETE', '/connections/steam'],
        ];
        $revoked = accountBearer($this->user);
        $token = VoiceClientToken::query()->where('token_hash', hash('sha256', substr($revoked['Authorization'], 7)))->firstOrFail();
        $token->forceFill(['revoked_at' => now()])->save();

        foreach ($routes as [$method, $path]) {
            $this->json($method, NATIVE_ACCOUNT_V1.$path)->assertUnauthorized();
            $this->json($method, NATIVE_ACCOUNT_V1.$path, [], ['Authorization' => 'Bearer garbage'])->assertUnauthorized();
            $this->json($method, NATIVE_ACCOUNT_V1.$path, [], $revoked)->assertUnauthorized();
        }
    });

    it('does not accept a bearer token on the web settings routes', function () {
        $this->getJson('/settings/connections', $this->headers)->assertUnauthorized();
        $this->patchJson('/settings/profile', ['name' => 'x', 'email' => 'x@example.test'], $this->headers)->assertUnauthorized();
    });
});

describe('profile', function () {
    it('shows the own profile like the settings page, without role and discord id', function () {
        $response = $this->getJson(NATIVE_ACCOUNT_V1.'/profile', $this->headers)->assertOk();

        expect($response->json('profile'))->toMatchArray([
            'id' => $this->user->id, 'name' => 'Ada', 'email' => 'ada@example.test', 'bio' => 'hi',
            // No local copy yet: null, never the remembered CDN address (see tests/Feature/Identity/AvatarOutputsTest).
            'avatar_url' => null, 'has_verified_steam_link' => false,
        ])->not->toHaveKeys(['role', 'discord_id', 'password'])
            ->and($response->getContent())->not->toContain('cdn.example.test');
    });

    it('updates through the browser rules with PUT and resets email verification on change', function () {
        $this->user->forceFill(['email_verified_at' => now()])->save();

        $this->putJson(NATIVE_ACCOUNT_V1.'/profile', [
            'name' => 'Ada L.', 'email' => 'new@example.test', 'bio' => 'hello', 'profile_color' => '#aabbcc',
            'steam_url' => 'https://steamcommunity.com/id/ada', 'stream_url' => 'https://twitch.tv/ada',
        ], $this->headers)->assertOk()->assertJsonPath('profile.name', 'Ada L.')->assertJsonPath('profile.email_verified', false);

        $this->user->refresh();
        expect($this->user->bio)->toBe('hello')->and($this->user->email)->toBe('new@example.test')->and($this->user->profile_color)->toBe('#aabbcc');
    });

    it('lets PATCH change a single field and PUT still requires name and email', function () {
        $this->patchJson(NATIVE_ACCOUNT_V1.'/profile', ['bio' => 'only bio'], $this->headers)->assertOk();
        expect($this->user->refresh()->bio)->toBe('only bio')->and($this->user->name)->toBe('Ada');

        $this->putJson(NATIVE_ACCOUNT_V1.'/profile', ['bio' => 'x'], $this->headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);
    });

    it('returns validation errors as 422 JSON with the browser rules', function () {
        $other = User::factory()->create(['email' => 'taken@example.test']);

        $this->patchJson(NATIVE_ACCOUNT_V1.'/profile', [
            'email' => 'taken@example.test', 'steam_url' => 'https://evil.example.test/x', 'stream_url' => 'http://insecure.example.test',
            'profile_color' => 'red', 'bio' => str_repeat('a', 1001),
        ], $this->headers)->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'steam_url', 'stream_url', 'profile_color', 'bio']);

        expect($other->refresh()->email)->toBe('taken@example.test');
    });

    it('ignores role, discord id, avatar and a foreign user id in the body', function () {
        $victim = User::factory()->create(['name' => 'Victim']);

        $this->putJson(NATIVE_ACCOUNT_V1.'/profile', [
            'name' => 'Ada', 'email' => 'ada@example.test',
            'role' => 'participant', 'discord_id' => '1', 'avatar_url' => 'https://evil.example.test/x.png',
            'id' => $victim->id, 'user_id' => $victim->id,
        ], $this->headers)->assertOk();

        $this->user->refresh();
        expect($this->user->role->value)->toBe('admin')->and($this->user->discord_id)->toBe('4242')
            ->and($this->user->avatar_url)->toBe('https://cdn.example.test/a.png')
            ->and($victim->refresh()->name)->toBe('Victim');
    });
});

describe('connections', function () {
    it('lists discord read-only and every linkable provider with its status', function () {
        LinkedAccount::factory()->for($this->user)->create([
            'provider' => LinkedAccountProvider::Twitch, 'nickname' => 'streamer1', 'access_token' => 'secret-token',
            'meta' => ['needs_reauth' => true],
        ]);

        $response = $this->getJson(NATIVE_ACCOUNT_V1.'/connections', $this->headers)->assertOk();
        $byProvider = collect($response->json('connections'))->keyBy('provider');

        expect($byProvider->keys()->all())->toBe(['discord', 'steam', 'twitch'])
            ->and($byProvider['discord'])->toMatchArray(['status' => 'connected', 'read_only' => true, 'can_unlink' => false, 'can_link' => false])
            ->and($byProvider['steam'])->toMatchArray(['status' => 'not_connected', 'available' => true, 'can_link' => true, 'can_unlink' => false, 'external_name' => null])
            ->and($byProvider['twitch'])->toMatchArray(['status' => 'needs_reauth', 'external_name' => 'streamer1', 'can_link' => true, 'can_unlink' => true]);
        expect($response->getContent())->not->toContain('secret-token');
    });

    it('flags an unconfigured provider as unavailable and not linkable', function () {
        config(['services.steam.client_secret' => null]);

        $steam = collect($this->getJson(NATIVE_ACCOUNT_V1.'/connections', $this->headers)->json('connections'))->firstWhere('provider', 'steam');

        expect($steam)->toMatchArray(['available' => false, 'can_link' => false]);
        $this->postJson(NATIVE_ACCOUNT_V1.'/connections/steam/link-url', [], $this->headers)->assertNotFound();
    });

    it('unlinks the own account only and is idempotent', function () {
        $mine = LinkedAccount::factory()->for($this->user)->create(['provider' => LinkedAccountProvider::Twitch]);
        $theirs = LinkedAccount::factory()->for(User::factory()->create())->create(['provider' => LinkedAccountProvider::Twitch]);

        $this->deleteJson(NATIVE_ACCOUNT_V1.'/connections/twitch', [], $this->headers)
            ->assertOk()->assertJsonPath('connection.status', 'not_connected');
        $this->deleteJson(NATIVE_ACCOUNT_V1.'/connections/twitch', [], $this->headers)->assertOk();

        expect(LinkedAccount::query()->find($mine->id))->toBeNull()->and(LinkedAccount::query()->find($theirs->id))->not->toBeNull();
    });

    it('cannot unlink or link discord or an unknown provider', function () {
        $this->deleteJson(NATIVE_ACCOUNT_V1.'/connections/discord', [], $this->headers)->assertNotFound();
        $this->postJson(NATIVE_ACCOUNT_V1.'/connections/discord/link-url', [], $this->headers)->assertNotFound();
        $this->deleteJson(NATIVE_ACCOUNT_V1.'/connections/nope', [], $this->headers)->assertNotFound();
    });
});

describe('link url', function () {
    it('issues a url on the web app that expires in 10 minutes', function () {
        $response = $this->postJson(NATIVE_ACCOUNT_V1.'/connections/steam/link-url', [], $this->headers)->assertOk();

        expect($response->json('provider'))->toBe('steam')
            ->and($response->json('url'))->toStartWith(url('/settings/connections/link/'))
            ->and($response->json('expires_at'))->toBe(now()->addMinutes(10)->toIso8601String());
    });

    function nativeLinkToken(array $response): string
    {
        return basename((string) parse_url($response['url'], PHP_URL_PATH));
    }

    it('sends a guest through the login first and stays usable afterwards', function () {
        $issued = $this->postJson(NATIVE_ACCOUNT_V1.'/connections/steam/link-url', [], $this->headers)->json();
        $path = parse_url($issued['url'], PHP_URL_PATH);

        $this->get($path)->assertRedirect(route('login'));

        // Not burned by the guest visit: the logged-in owner still gets through.
        $this->actingAs($this->user)->get($path)->assertRedirect(route('connections.redirect', 'steam'));
    });

    it('starts the normal link flow for the owner exactly once', function () {
        $issued = $this->postJson(NATIVE_ACCOUNT_V1.'/connections/twitch/link-url', [], $this->headers)->json();
        $path = parse_url($issued['url'], PHP_URL_PATH);

        $this->actingAs($this->user)->get($path)->assertRedirect(route('connections.redirect', 'twitch'));
        $this->actingAs($this->user)->get($path)->assertStatus(410);
    });

    it('refuses a foreign user without burning the token', function () {
        $issued = $this->postJson(NATIVE_ACCOUNT_V1.'/connections/steam/link-url', [], $this->headers)->json();
        $path = parse_url($issued['url'], PHP_URL_PATH);

        $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
        $this->actingAs($this->user)->get($path)->assertRedirect(route('connections.redirect', 'steam'));
    });

    it('expires after 10 minutes and rejects unknown tokens', function () {
        $issued = $this->postJson(NATIVE_ACCOUNT_V1.'/connections/steam/link-url', [], $this->headers)->json();
        $path = parse_url($issued['url'], PHP_URL_PATH);

        Carbon::setTestNow(now()->addMinutes(NativeLinkTokens::TTL_MINUTES)->addSecond());

        $this->actingAs($this->user)->get($path)->assertStatus(410);
        $this->actingAs($this->user)->get('/settings/connections/link/'.str_repeat('a', 48))->assertStatus(410);
    });

    it('does not store the plain token', function () {
        $issued = $this->postJson(NATIVE_ACCOUNT_V1.'/connections/steam/link-url', [], $this->headers)->json();
        $token = nativeLinkToken($issued);

        expect(Cache::has('native-link:'.$token))->toBeFalse()->and(Cache::has('native-link:'.hash('sha256', $token)))->toBeTrue();
    });
});
