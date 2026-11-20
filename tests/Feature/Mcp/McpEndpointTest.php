<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Support\McpCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function mintMcpEndpointToken(User $user, array $overrides = []): array
{
    $plain = McpCodes::bearerToken();

    $token = (new McpToken)->forceFill(array_merge([
        'user_id' => $user->getKey(),
        'name' => 'Endpoint test',
        'token_hash' => McpCodes::hash($plain),
        'expires_at' => now()->addDays(90),
    ], $overrides));

    $token->save();

    return [$plain, $token];
}

/**
 * @return array<string, mixed>
 */
function mcpInitializeBody(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => ['roots' => ['listChanged' => false]],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0'],
        ],
    ];
}

function mcpInitializeRequest(string $plain): TestResponse
{
    return test()->withHeaders([
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$plain,
    ])->postJson('/mcp', mcpInitializeBody());
}

it('rejects a session-less, token-less MCP request with 401 and WWW-Authenticate', function () {
    $response = $this->postJson('/mcp', mcpInitializeBody());

    $response->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');

    // laravel/mcp's AddWwwAuthenticateHeader decorates our 401 (it runs
    // outside the bearer check), so the challenge names the realm.
    expect((string) $response->headers->get('WWW-Authenticate'))
        ->toStartWith('Bearer')
        ->toContain('realm="mcp"');
});

it('rejects a stale browser session and a bad token on the MCP endpoint', function () {
    $user = User::factory()->create();

    $session = $this->actingAs($user)->postJson('/mcp', mcpInitializeBody());
    $session->assertStatus(401);
    expect((string) $session->headers->get('WWW-Authenticate'))->toStartWith('Bearer');

    $this->withHeaders([
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer lmcp_nope',
    ])->postJson('/mcp', mcpInitializeBody())
        ->assertStatus(401)
        ->assertJsonPath('message', 'Unauthenticated.');
});

it('rejects a revoked token on the MCP endpoint immediately', function () {
    $user = User::factory()->create();
    [$plain, $token] = mintMcpEndpointToken($user);

    mcpInitializeRequest($plain)->assertOk();

    $token->forceFill(['revoked_at' => now()])->save();

    $response = mcpInitializeRequest($plain);
    $response->assertStatus(401);

    expect((string) $response->headers->get('WWW-Authenticate'))->toStartWith('Bearer');
});

it('accepts a valid token on the MCP endpoint', function () {
    $user = User::factory()->create();
    [$plain] = mintMcpEndpointToken($user);

    $response = mcpInitializeRequest($plain)->assertOk();

    expect((string) $response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->json('jsonrpc'))->toBe('2.0')
        ->and($response->json('result.protocolVersion'))->toBe('2025-06-18');
});

it('answers GET and DELETE with the 405 stub from laravel/mcp', function () {
    // The package registers GET/DELETE as 405 stubs (Allow: POST) without the
    // bearer middleware — they never reach a tool, so no token is required.
    // POST is the only authenticated verb; see the 401 tests above.
    $this->get('/mcp')->assertStatus(405)->assertHeader('Allow', 'POST');
    $this->delete('/mcp')->assertStatus(405)->assertHeader('Allow', 'POST');
});

it('rejects a foreign Origin with a JSON-RPC error before any tool runs', function () {
    Config::set('app.url', 'https://lan.example');
    $user = User::factory()->create();
    [$plain] = mintMcpEndpointToken($user);

    $this->withHeaders([
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$plain,
        'Origin' => 'https://evil.example',
    ])->postJson('/mcp', mcpInitializeBody())
        ->assertStatus(403)
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('error.message', 'Forbidden: The request origin is not allowed.');
});

it('allows the configured app origin, localhost and no Origin at all', function () {
    // Documented behaviour of laravel/mcp's ValidateOrigin: the Origin header is
    // optional (native clients such as pi/Claude Desktop send none), localhost
    // is always allowed for local tooling, and otherwise Origin must match
    // app.url (scheme + host + non-default port) or config('mcp.allowed_origins').
    Config::set('app.url', 'https://lan.example');
    $user = User::factory()->create();
    [$plain] = mintMcpEndpointToken($user);

    foreach (['https://lan.example', 'https://lan.example:443', 'http://localhost:5173', 'http://127.0.0.1:8000'] as $origin) {
        $this->withHeaders([
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => 'Bearer '.$plain,
            'Origin' => $origin,
        ])->postJson('/mcp', mcpInitializeBody())->assertOk();
    }

    // no Origin header: allowed (a native MCP client is not a browser)
    mcpInitializeRequest($plain)->assertOk();
});

it('rejects a scheme mismatch and a non-HTTP Origin', function () {
    Config::set('app.url', 'https://lan.example');
    $user = User::factory()->create();
    [$plain] = mintMcpEndpointToken($user);

    foreach (['http://lan.example', 'https://lan.example:8443', 'null', 'file://lan.example'] as $origin) {
        $this->withHeaders([
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => 'Bearer '.$plain,
            'Origin' => $origin,
        ])->postJson('/mcp', mcpInitializeBody())->assertStatus(403);
    }
});

it('checks Origin before the bearer token, so a foreign origin never reaches the auth code', function () {
    Config::set('app.url', 'https://lan.example');

    // laravel/mcp's ValidateOrigin runs outside our bearer middleware: an
    // unauthenticated browser request from a foreign origin gets 403 without
    // the token being looked up at all.
    $this->withHeaders(['Origin' => 'https://evil.example'])
        ->postJson('/mcp', mcpInitializeBody())
        ->assertStatus(403)
        ->assertJsonPath('error.message', 'Forbidden: The request origin is not allowed.');
});
