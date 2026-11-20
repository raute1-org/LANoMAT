<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Mcp\Actions\RevokeMcpToken;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Support\McpCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function mintMcpToken(User $user, array $overrides = []): array
{
    $plain = McpCodes::bearerToken();

    $token = (new McpToken)->forceFill(array_merge([
        'user_id' => $user->getKey(),
        'name' => 'Test agent',
        'token_hash' => McpCodes::hash($plain),
        'expires_at' => now()->addDays(90),
    ], $overrides));

    $token->save();

    return [$plain, $token];
}

afterEach(function (): void {
    Carbon::setTestNow();
    cache()->store((string) config('cache.limiter'))->flush();
});

beforeEach(function (): void {
    cache()->store((string) config('cache.limiter'))->flush();
});

it('rejects an unknown, empty or oversized bearer token', function () {
    $this->withToken('lmcp_unknown')->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer');

    $this->withToken('')->getJson('/api/v1/me')->assertUnauthorized();
    $this->withToken(str_repeat('a', 201))->getJson('/api/v1/me')->assertUnauthorized();
});

it('rejects an expired token without sliding it back to life', function () {
    Carbon::setTestNow('2026-11-21 18:00:00');
    $user = User::factory()->create();

    [$plain, $token] = mintMcpToken($user, [
        'expires_at' => now()->subMinute(),
        'last_used_at' => now()->subDay(),
    ]);

    $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();

    $expired = $token->refresh();

    expect($expired->expires_at?->isPast())->toBeTrue()
        ->and($expired->last_used_at?->getTimestamp())->toBe(now()->subDay()->getTimestamp())
        ->and($expired->isActive())->toBeFalse();
});

it('slides last_used_at and the TTL forward on use, at most every five minutes', function () {
    Carbon::setTestNow('2026-11-21 18:00:00');
    $user = User::factory()->create();

    [$plain, $token] = mintMcpToken($user, [
        'expires_at' => now()->addHour(),
        'last_used_at' => now()->subMinutes(10),
    ]);

    $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

    $touched = $token->refresh();

    expect($touched->last_used_at?->getTimestamp())->toBe(now()->getTimestamp())
        ->and($touched->expires_at?->getTimestamp())->toBe(now()->addDays(90)->getTimestamp());

    // a second request inside the touch window must not write again
    $writtenAt = $touched->expires_at?->copy();
    Carbon::setTestNow(now()->addSeconds(100));

    $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

    expect($token->refresh()->expires_at?->getTimestamp())->toBe($writtenAt?->getTimestamp());

    // after the window the sliding continues
    Carbon::setTestNow(now()->addSeconds(210));

    $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

    expect($token->refresh()->expires_at?->getTimestamp())->toBe(now()->addDays(90)->getTimestamp());
});

it('fails a revoked token immediately and keeps the revocation timestamp', function () {
    Carbon::setTestNow('2026-11-21 18:00:00');
    $user = User::factory()->create();
    [$plain, $token] = mintMcpToken($user);

    expect($token->isActive())->toBeTrue();

    app(RevokeMcpToken::class)->handle($user, $token);

    $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();

    $revokedAt = $token->refresh()->revoked_at?->copy();
    expect($revokedAt)->not->toBeNull();

    Carbon::setTestNow(now()->addMinutes(5));
    app(RevokeMcpToken::class)->handle($user, $token);

    expect($token->refresh()->revoked_at?->equalTo($revokedAt))->toBeTrue()
        ->and($token->last_used_at)->toBeNull();
});

it('rejects a token whose user was deleted', function () {
    $user = User::factory()->create();
    [$plain] = mintMcpToken($user);

    $user->delete();

    // the foreign key cascades, so no live row can outlive its user
    expect(McpToken::query()->count())->toBe(0);

    $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
});

it('never authenticates a browser session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/me')->assertUnauthorized();
    $this->actingAs($user)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
        ->assertUnauthorized();
});

it('lets the owner revoke a device and refuses a stranger', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    [$plain, $token] = mintMcpToken($owner);

    $this->actingAs($stranger)
        ->delete(route('mcp.devices.revoke', $token))
        ->assertForbidden();

    expect($token->refresh()->revoked_at)->toBeNull();
    $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

    $this->actingAs($owner)
        ->delete(route('mcp.devices.revoke', $token))
        ->assertRedirect(route('mcp.connect'));

    expect($token->refresh()->revoked_at)->not->toBeNull();
    $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
});

it('lets an admin revoke a foreign device and sends guests to the login', function () {
    $owner = User::factory()->create();
    [$plain, $token] = mintMcpToken($owner);

    $this->delete(route('mcp.devices.revoke', $token))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->admin()->create())
        ->delete(route('mcp.devices.revoke', $token))
        ->assertRedirect(route('mcp.connect'));

    expect($token->refresh()->revoked_at)->not->toBeNull();
    $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
});

it('lists only active devices on the connect page and never exposes digests', function () {
    $user = User::factory()->create();
    [$activePlain, $active] = mintMcpToken($user, ['name' => 'Active']);
    mintMcpToken($user, ['name' => 'Old', 'expires_at' => now()->subDay()]);
    mintMcpToken($user, ['name' => 'Revoked', 'revoked_at' => now()->subHour()]);

    $response = $this->withoutVite()->actingAs($user)->get('/mcp/connect');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Mcp/Connect')
        ->has('devices', 1)
        ->where('devices.0.id', $active->id)
        ->where('devices.0.name', 'Active')
        ->missing('devices.0.token_hash'));

    $content = (string) $response->getContent();

    expect($content)->not->toContain($activePlain)
        ->and($content)->not->toContain(McpCodes::hash($activePlain))
        ->and($content)->not->toContain('token_hash');
});

it('stores no plaintext token in the database', function () {
    $user = User::factory()->create();
    [$plain] = mintMcpToken($user);

    $row = DB::table('mcp_tokens')->first();
    $flat = implode('|', array_map(static fn ($value): string => (string) $value, (array) $row));

    expect($row->token_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($row->token_hash)->not->toBe($plain)
        ->and($flat)->not->toContain($plain);
});

it('exposes the reserved ability list (not yet enforced)', function () {
    // No mint path writes `abilities` and no middleware or tool reads
    // hasAbility(); the column exists so scopes can be added later without a
    // migration. Effective permissions are always the user's role/policies.
    $user = User::factory()->create();
    [$scopedPlain, $scoped] = mintMcpToken($user, ['abilities' => ['read']]);
    [$fullPlain, $full] = mintMcpToken($user, ['abilities' => null]);

    expect($scoped->hasAbility('read'))->toBeTrue()
        ->and($scoped->hasAbility('write'))->toBeFalse()
        ->and($full->hasAbility('write'))->toBeTrue();

    $this->withToken($scopedPlain)->getJson('/api/v1/me')->assertOk();
    $this->withToken($fullPlain)->getJson('/api/v1/me')->assertOk();
});

it('throttles the agent API per token without punishing another token', function () {
    $user = User::factory()->create();

    [$plain] = mintMcpToken($user);

    for ($i = 0; $i < 240; $i++) {
        $this->withToken($plain)->getJson('/api/v1/me')->assertOk();
    }

    $this->withToken($plain)->getJson('/api/v1/me')->assertStatus(429);

    [$otherPlain] = mintMcpToken($user);
    $this->withToken($otherPlain)->getJson('/api/v1/me')->assertOk();
});

it('throttles the connect page per user', function () {
    $user = User::factory()->create();
    $this->withoutVite();

    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($user)->get('/mcp/connect')->assertOk();
    }

    $this->actingAs($user)->get('/mcp/connect')->assertStatus(429);
});
