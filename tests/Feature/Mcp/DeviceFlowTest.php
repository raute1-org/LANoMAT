<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Mcp\Actions\DecideDeviceAuthorization;
use App\Modules\Mcp\Actions\ExchangeDeviceCode;
use App\Modules\Mcp\Actions\RevokeMcpToken;
use App\Modules\Mcp\Actions\StartDeviceAuthorization;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Support\DeviceFlowError;
use App\Modules\Mcp\Support\McpCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('pairs an agent end to end and issues a token once', function () {
    $user = User::factory()->create();
    $start = app(StartDeviceAuthorization::class);

    $pair = $start->handle('Rene-PC');

    expect($pair['user_code'])->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/')
        ->and($pair['interval'])->toBe(5)
        ->and($pair['verification_uri'])->toContain('/mcp/connect');

    // pending until the browser approves
    expect(fn () => app(ExchangeDeviceCode::class)->handle($pair['device_code']))
        ->toThrow(DeviceFlowError::class, 'authorization_pending');

    expect(app(DecideDeviceAuthorization::class)->handle($user, $pair['user_code'], true))->toBeTrue();

    Carbon::setTestNow(now()->addSeconds(6));

    $token = app(ExchangeDeviceCode::class)->handle($pair['device_code']);

    expect($token['token_type'])->toBe('Bearer')
        ->and($token['access_token'])->toStartWith('lmcp_');

    $row = McpToken::query()->sole();

    expect($row->user_id)->toBe($user->id)
        ->and($row->token_hash)->toBe(McpCodes::hash($token['access_token']))
        ->and($row->token_hash)->not->toBe($token['access_token']);

    // the code is spent
    expect(fn () => app(ExchangeDeviceCode::class)->handle($pair['device_code']))
        ->toThrow(DeviceFlowError::class, 'invalid_grant');

    Carbon::setTestNow();
});

it('returns authorization_pending, slow_down and access_denied as machine codes', function () {
    $user = User::factory()->create();
    $pair = app(StartDeviceAuthorization::class)->handle('Laptop');

    try {
        app(ExchangeDeviceCode::class)->handle($pair['device_code']);
    } catch (DeviceFlowError $error) {
        expect($error->error)->toBe('authorization_pending');
    }

    // immediate second poll is too fast
    try {
        app(ExchangeDeviceCode::class)->handle($pair['device_code']);
    } catch (DeviceFlowError $error) {
        expect($error->error)->toBe('slow_down')
            ->and($error->interval)->toBe(10);
    }

    expect(app(DecideDeviceAuthorization::class)->handle($user, $pair['user_code'], false))->toBeTrue();

    Carbon::setTestNow(now()->addSeconds(11));

    try {
        app(ExchangeDeviceCode::class)->handle($pair['device_code']);
    } catch (DeviceFlowError $error) {
        expect($error->error)->toBe('access_denied');
    }

    Carbon::setTestNow();
});

it('expires codes and rejects them through the HTTP endpoint', function () {
    $pair = app(StartDeviceAuthorization::class)->handle('Old agent');

    Carbon::setTestNow(now()->addSeconds(601));

    $this->postJson('/api/mcp/token', ['device_code' => $pair['device_code']])
        ->assertStatus(400)
        ->assertJson(['error' => 'expired_token']);

    Carbon::setTestNow();
});

it('rejects unknown user codes and unknown tokens', function () {
    $user = User::factory()->create();

    expect(app(DecideDeviceAuthorization::class)->handle($user, 'ZZZZ-ZZZZ', true))->toBeFalse();

    $this->getJson('/api/v1/me')->assertUnauthorized();

    $this->withToken('lmcp_nope')->getJson('/api/v1/me')->assertUnauthorized();
});

it('authenticates the bearer token on the REST API and revokes it', function () {
    $user = User::factory()->create();
    $pair = app(StartDeviceAuthorization::class)->handle('CLI');
    app(DecideDeviceAuthorization::class)->handle($user, $pair['user_code'], true);
    $token = app(ExchangeDeviceCode::class)->handle($pair['device_code'])['access_token'];

    $this->withToken($token)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('server.api_version', 1);

    $mcptoken = McpToken::query()->sole();
    app(RevokeMcpToken::class)->handle($user, $mcptoken);

    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});

it('does not accept a session instead of a bearer token', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/me')->assertUnauthorized();
});
