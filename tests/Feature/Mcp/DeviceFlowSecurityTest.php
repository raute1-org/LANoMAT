<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Mcp\Actions\DecideDeviceAuthorization;
use App\Modules\Mcp\Actions\ExchangeDeviceCode;
use App\Modules\Mcp\Actions\ResolveUserCode;
use App\Modules\Mcp\Actions\StartDeviceAuthorization;
use App\Modules\Mcp\Models\McpDeviceCode;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Support\DeviceFlowError;
use App\Modules\Mcp\Support\McpCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

/**
 * Device flow: the user code is guessed by a human, the device code is the
 * polling credential. Nothing plaintext may reach the database, a code is
 * spent exactly once, and every poll interval increase survives the request.
 */
function mcpSecurityPair(string $deviceName = 'Agent'): array
{
    return app(StartDeviceAuthorization::class)->handle($deviceName);
}

function mcpSecurityPoll(string $deviceCode): DeviceFlowError
{
    try {
        app(ExchangeDeviceCode::class)->handle($deviceCode);
    } catch (DeviceFlowError $error) {
        return $error;
    }

    throw new RuntimeException('Expected the device flow to fail.');
}

/**
 * The limiter cache is process-wide in tests; other files' requests must not
 * leak into these assertions (and these requests must not leak out).
 */
beforeEach(function (): void {
    cache()->store((string) config('cache.limiter'))->flush();
});

afterEach(function (): void {
    Carbon::setTestNow();
    cache()->store((string) config('cache.limiter'))->flush();
});

it('stores only SHA-256 digests of both codes and of the token', function () {
    $user = User::factory()->create();
    $pair = mcpSecurityPair('Digest test');

    $pair['user_code'] = (string) $pair['user_code'];

    $row = DB::table('mcp_device_codes')->first();

    expect($row)->not->toBeNull()
        ->and($row->device_code_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($row->user_code_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($row->device_code_hash)->not->toBe($pair['device_code'])
        ->and($row->device_code_hash)->toBe(McpCodes::hash($pair['device_code']))
        ->and($row->user_code_hash)->toBe(McpCodes::hash(McpCodes::normalize($pair['user_code'])));

    $flat = implode('|', array_map(static fn ($value): string => (string) $value, (array) $row));

    expect($flat)->not->toContain($pair['device_code'])
        ->and($flat)->not->toContain($pair['user_code'])
        ->and($flat)->not->toContain(McpCodes::normalize($pair['user_code']));

    app(DecideDeviceAuthorization::class)->handle($user, $pair['user_code'], true);
    Carbon::setTestNow(now()->addSeconds(6));

    $token = app(ExchangeDeviceCode::class)->handle($pair['device_code']);
    $issued = DB::table('mcp_tokens')->first();

    expect($issued)->not->toBeNull()
        ->and($issued->token_hash)->toBe(McpCodes::hash($token['access_token']))
        ->and($issued->token_hash)->not->toBe($token['access_token']);

    $flatToken = implode('|', array_map(static fn ($value): string => (string) $value, (array) $issued));

    expect($flatToken)->not->toContain($token['access_token']);
});

it('spends a device code exactly once and deletes the pairing row', function () {
    $user = User::factory()->create();
    $pair = mcpSecurityPair();

    app(DecideDeviceAuthorization::class)->handle($user, $pair['user_code'], true);

    Carbon::setTestNow(now()->addSeconds(6));
    app(ExchangeDeviceCode::class)->handle($pair['device_code']);

    expect(McpDeviceCode::query()->count())->toBe(0);

    Carbon::setTestNow(now()->addSeconds(60));

    expect(mcpSecurityPoll($pair['device_code'])->error)->toBe('invalid_grant')
        ->and(McpToken::query()->count())->toBe(1);
});

it('keeps growing and persisting the slow_down interval across polls', function () {
    Carbon::setTestNow('2026-11-21 18:00:00');
    $pair = mcpSecurityPair();

    // first poll: pending, records the poll time
    expect(mcpSecurityPoll($pair['device_code'])->error)->toBe('authorization_pending');

    // immediate second poll: RFC 8628 slow_down, +5s, persisted
    $slow = mcpSecurityPoll($pair['device_code']);
    expect($slow->error)->toBe('slow_down')->and($slow->interval)->toBe(10);

    $row = McpDeviceCode::query()->sole();
    expect($row->interval)->toBe(10)
        ->and($row->last_polled_at?->equalTo(now()))->toBeTrue();

    // immediate third poll: grows again from the persisted value
    $slower = mcpSecurityPoll($pair['device_code']);
    expect($slower->error)->toBe('slow_down')->and($slower->interval)->toBe(15)
        ->and(McpDeviceCode::query()->sole()->interval)->toBe(15);

    // waiting out the raised interval returns to a normal pending poll
    Carbon::setTestNow(now()->addSeconds(15));
    expect(mcpSecurityPoll($pair['device_code'])->error)->toBe('authorization_pending');
});

it('reports access_denied and invalid_grant to the agent and forgets the code', function () {
    $user = User::factory()->create();
    $pair = mcpSecurityPair();

    expect(mcpSecurityPoll($pair['device_code'])->error)->toBe('authorization_pending');

    app(DecideDeviceAuthorization::class)->handle($user, $pair['user_code'], false);

    Carbon::setTestNow(now()->addSeconds(6));
    expect(mcpSecurityPoll($pair['device_code'])->error)->toBe('access_denied')
        ->and(McpDeviceCode::query()->count())->toBe(0);

    Carbon::setTestNow(now()->addSeconds(6));
    expect(mcpSecurityPoll($pair['device_code'])->error)->toBe('invalid_grant')
        ->and(McpToken::query()->count())->toBe(0);
});

it('expires a code after the configured TTL and rejects approval afterwards', function () {
    $user = User::factory()->create();
    $pair = mcpSecurityPair();

    Carbon::setTestNow(now()->addSeconds(601));

    expect(mcpSecurityPoll($pair['device_code'])->error)->toBe('expired_token')
        ->and(McpDeviceCode::query()->count())->toBe(0)
        ->and(app(DecideDeviceAuthorization::class)->handle($user, $pair['user_code'], true))->toBeFalse()
        ->and(app(ResolveUserCode::class)->handle($user, $pair['user_code']))->toBeNull();
});

it('refuses to approve an expired, already decided or unknown user code', function () {
    $user = User::factory()->create();
    $decide = app(DecideDeviceAuthorization::class);

    expect($decide->handle($user, 'ZZZZ-ZZZZ', true))->toBeFalse()
        ->and($decide->handle($user, 'too-short', true))->toBeFalse();

    $pair = mcpSecurityPair();

    expect($decide->handle($user, $pair['user_code'], true))->toBeTrue()
        ->and($decide->handle($user, $pair['user_code'], true))->toBeFalse()
        ->and($decide->handle($user, $pair['user_code'], false))->toBeFalse();

    $expired = mcpSecurityPair();
    Carbon::setTestNow(now()->addSeconds(601));

    expect($decide->handle($user, $expired['user_code'], true))->toBeFalse();
});

it('binds the token to the user who approved the code', function () {
    $approver = User::factory()->create();
    $pair = mcpSecurityPair();

    app(DecideDeviceAuthorization::class)->handle($approver, $pair['user_code'], true);
    Carbon::setTestNow(now()->addSeconds(6));

    app(ExchangeDeviceCode::class)->handle($pair['device_code']);

    expect(McpToken::query()->sole()->user_id)->toBe($approver->id);
});

it('normalises typed user codes without accepting the wrong length', function () {
    $code = McpCodes::userCode();

    expect(McpCodes::normalize(strtolower(McpCodes::format($code))))->toBe($code)
        ->and(McpCodes::normalize(' '.substr($code, 0, 4).' - '.substr($code, 4).' '))->toBe($code)
        ->and(McpCodes::normalize('ABC'))->toBeNull()
        ->and(McpCodes::normalize('ABCDEFGHI'))->toBeNull()
        ->and(McpCodes::format($code))->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/')
        ->and(McpCodes::matches($code, McpCodes::hash($code)))->toBeTrue()
        ->and(McpCodes::matches(strtolower($code), McpCodes::hash($code)))->toBeFalse();
});

it('never generates look-alike characters for a user code', function () {
    for ($i = 0; $i < 20; $i++) {
        expect(McpCodes::userCode())->not->toMatch('/[01OIL]/');
    }
});

it('cleans device names and caps them at the column width', function () {
    $pair = mcpSecurityPair("  Rene\x07-PC  ");
    expect(McpDeviceCode::query()->sole()->device_name)->toBe('Rene-PC');

    mcpSecurityPair(str_repeat('A', 200));
    expect(McpDeviceCode::query()->orderByDesc('id')->first()?->device_name)->toHaveLength(60);

    mcpSecurityPair(" \x01\x02 ");
    expect(McpDeviceCode::query()->orderByDesc('id')->first()?->device_name)->toBe('LANoMAT agent');
});

it('shapes every device-flow failure as a 400 with a machine-readable error', function () {
    $missing = $this->postJson('/api/mcp/token', []);

    $missing->assertStatus(400)->assertExactJson(['error' => 'invalid_request']);
    expect((string) $missing->headers->get('Cache-Control'))->toContain('no-store');

    $this->postJson('/api/mcp/token', ['device_code' => ['nested']])
        ->assertStatus(400)
        ->assertExactJson(['error' => 'invalid_request']);

    $this->postJson('/api/mcp/token', ['device_code' => str_repeat('x', 129)])
        ->assertStatus(400)
        ->assertExactJson(['error' => 'invalid_request']);

    $this->postJson('/api/mcp/token', ['device_code' => 'never-issued'])
        ->assertStatus(400)
        ->assertExactJson(['error' => 'invalid_grant']);

    $this->postJson('/api/mcp/device', ['device_name' => ['nested']])
        ->assertStatus(400)
        ->assertExactJson(['error' => 'invalid_request']);

    $this->postJson('/api/mcp/device', ['device_name' => str_repeat('x', 121)])
        ->assertStatus(400)
        ->assertExactJson(['error' => 'invalid_request']);
});

it('never lets a cache store the device or token response', function () {
    $start = $this->postJson('/api/mcp/device', ['device_name' => 'Cache test']);

    $start->assertOk();
    expect((string) $start->headers->get('Cache-Control'))->toContain('no-store');
    expect($start->json())->toHaveKeys([
        'device_code', 'user_code', 'verification_uri', 'verification_uri_complete', 'expires_in', 'interval',
    ]);

    $error = $this->postJson('/api/mcp/token', ['device_code' => 'unknown']);

    expect((string) $error->headers->get('Cache-Control'))->toContain('no-store');
});

it('completes the pairing over HTTP and prints the verification link', function () {
    $user = User::factory()->create();

    $pair = $this->postJson('/api/mcp/device', ['device_name' => 'HTTP agent'])->assertOk()->json();

    expect($pair['verification_uri_complete'])->toContain(urlencode($pair['user_code']));

    $this->postJson('/api/mcp/token', ['device_code' => $pair['device_code']])
        ->assertStatus(400)
        ->assertJson(['error' => 'authorization_pending']);

    $this->actingAs($user)
        ->post('/mcp/connect', ['code' => $pair['user_code'], 'decision' => 'approve'])
        ->assertRedirect(route('mcp.connect'));

    Carbon::setTestNow(now()->addSeconds(6));

    $token = $this->postJson('/api/mcp/token', ['device_code' => $pair['device_code']])
        ->assertOk()
        ->json();

    expect($token['access_token'])->toStartWith(McpCodes::TOKEN_PREFIX)
        ->and($token['expires_in'])->toBe(90 * 86400);

    $this->withToken($token['access_token'])->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);
});

it('throttles the device endpoint per IP', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/mcp/device')->assertOk();
    }

    $blocked = $this->postJson('/api/mcp/device')->assertStatus(429);

    expect($blocked->headers->has('Retry-After'))->toBeTrue();
});

it('throttles the token endpoint per IP', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/api/mcp/token', ['device_code' => 'code-'.$i])
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_grant']);
    }

    $this->postJson('/api/mcp/token', ['device_code' => 'code-last'])->assertStatus(429);
});

it('throttles the token endpoint per device code even behind rotating IPs', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.'.($i + 1)])
            ->postJson('/api/mcp/token', ['device_code' => 'shared-code'])
            ->assertStatus(400);
    }

    // a fresh IP does not help: the code itself is exhausted
    $this->withServerVariables(['REMOTE_ADDR' => '10.9.1.1'])
        ->postJson('/api/mcp/token', ['device_code' => 'shared-code'])
        ->assertStatus(429);

    // a different code from another fresh IP is still served
    $this->withServerVariables(['REMOTE_ADDR' => '10.9.2.1'])
        ->postJson('/api/mcp/token', ['device_code' => 'other-code'])
        ->assertStatus(400)
        ->assertJson(['error' => 'invalid_grant']);
});

it('keeps constant-time digest comparison in one place', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/Mcp')));
    $withHashEquals = [];
    $directComparisons = [];

    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (str_contains($source, 'hash_equals(')) {
            $withHashEquals[] = $file->getFilename();
        }

        if (preg_match('/_hash\s*===|_hash\s*!==/', $source) === 1) {
            $directComparisons[] = $file->getFilename();
        }
    }

    expect($withHashEquals)->toBe(['McpCodes.php'])
        ->and($directComparisons)->toBe([]);
});

it('does not resurrect an expired pairing row on a later poll', function () {
    $pair = mcpSecurityPair();

    Carbon::setTestNow(now()->addSeconds(601));
    expect(mcpSecurityPoll($pair['device_code'])->error)->toBe('expired_token');

    $this->assertDatabaseCount('mcp_device_codes', 0);

    // the same device code stays invalid forever, never a new pairing row
    Carbon::setTestNow(now()->addSeconds(1200));
    expect(mcpSecurityPoll($pair['device_code'])->error)->toBe('invalid_grant');
});

it('registers the named device-flow rate limiters', function () {
    expect(RateLimiter::limiter('mcp-device'))->not->toBeNull()
        ->and(RateLimiter::limiter('mcp-token'))->not->toBeNull();
});
