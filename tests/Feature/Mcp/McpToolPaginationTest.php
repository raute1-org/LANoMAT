<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Support\McpCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Tools every full-profile LANoMAT server exposes. The count and the recipe
 * block are what the launcher agent has to see; page 1 alone only has 15.
 *
 * @return list<string>
 */
function mcpPaginationToolNames(): array
{
    return [
        'lanomat_me',
        'events_list',
        'event_get',
        'event_register',
        'teams_list',
        'tournament_list',
        'tournament_bracket',
        'tournament_checkin',
        'match_result',
        'games_search',
        'files_list',
        'file_download_url',
        'servers_list',
        'schedule_list',
        'polls_list',
        'poll_vote',
        'catering_menu',
        'catering_menu_get',
        'catering_menu_preview',
        'catering_menu_import',
        'lfg_list',
        'lfg_create',
        'recipes_list',
        'recipe_get',
        'recipe_install_plan',
        'recipe_avatars',
        'recipe_contribute',
    ];
}

function mcpPaginationToken(): string
{
    $plain = McpCodes::bearerToken();

    (new McpToken)->forceFill([
        'user_id' => User::factory()->create()->getKey(),
        'name' => 'Pagination test',
        'token_hash' => McpCodes::hash($plain),
        'expires_at' => now()->addDays(90),
    ])->save();

    return $plain;
}

/**
 * @param  array<string, mixed>  $params
 */
function mcpToolsListResponse(string $token, array $params = []): TestResponse
{
    return test()->withHeaders([
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => $params === [] ? (object) [] : $params,
    ]);
}

/**
 * @param  array<string, mixed>  $params
 * @return array<string, mixed>
 */
function mcpToolsListRequest(string $token, array $params = []): array
{
    $response = mcpToolsListResponse($token, $params);
    $response->assertOk();

    return $response->json();
}
/**
 * @return array{names: list<string>, pages: int}
 */
function mcpCollectTools(string $token, int $perPage): array
{
    $names = [];
    $cursor = null;
    $pages = 0;

    do {
        $params = ['per_page' => $perPage];

        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        $json = mcpToolsListRequest($token, $params);
        $page = $json['result']['tools'] ?? null;

        expect($page)->toBeArray();

        foreach ($page as $tool) {
            $names[] = $tool['name'];
        }

        $cursor = $json['result']['nextCursor'] ?? null;
        $pages++;

        expect($pages)->toBeLessThanOrEqual(10);
    } while ($cursor !== null);

    return ['names' => $names, 'pages' => $pages];
}

it('returns all 27 tools on the default page, without a cursor', function () {
    $token = mcpPaginationToken();

    $json = mcpToolsListRequest($token);

    expect($json['result']['tools'])->toHaveCount(27)
        ->and(array_column($json['result']['tools'], 'name'))->toBe(mcpPaginationToolNames())
        ->and($json['result'])->not->toHaveKey('nextCursor');
});

it('collects all 27 tools through the cursor when a client asks for 15 per page', function () {
    $token = mcpPaginationToken();

    $first = mcpToolsListRequest($token, ['per_page' => 15]);

    expect($first['result']['tools'])->toHaveCount(15)
        ->and($first['result']['nextCursor'])->toBe(base64_encode('{"offset":15}'));

    $collected = mcpCollectTools($token, 15);

    expect($collected['pages'])->toBe(2)
        ->and($collected['names'])->toBe(mcpPaginationToolNames());
});

it('returns every tool on one page with per_page=100 and no nextCursor', function () {
    $token = mcpPaginationToken();

    $json = mcpToolsListRequest($token, ['per_page' => 100]);

    expect($json['result']['tools'])->toHaveCount(27)
        ->and(array_column($json['result']['tools'], 'name'))->toBe(mcpPaginationToolNames())
        ->and($json['result'])->not->toHaveKey('nextCursor');
});

it('accepts a numeric-string per_page and an unpadded base64url cursor', function () {
    $token = mcpPaginationToken();

    $first = mcpToolsListRequest($token, ['per_page' => '15']);
    $cursor = $first['result']['nextCursor'];

    expect($cursor)->toBeString();

    // A URL or log line can drop the `=` padding; decoding that as offset 0
    // used to replay page 1 and made the recipe tools invisible.
    $second = mcpToolsListRequest($token, [
        'per_page' => 15,
        'cursor' => rtrim($cursor, '='),
    ]);

    expect($second['result']['tools'][0]['name'])->toBe('poll_vote')
        ->and($second['result'])->not->toHaveKey('nextCursor');
});

it('rejects a malformed cursor instead of silently replaying page 1', function () {
    $token = mcpPaginationToken();

    $response = mcpToolsListResponse($token, ['cursor' => 'not-a-pagination-cursor']);

    // laravel/mcp turns an invalid-params JSON-RPC error into HTTP 400; the
    // body is still the JSON-RPC error the client has to see instead of page 1.
    $response->assertStatus(400)
        ->assertJsonPath('error.code', -32602);

    expect($response->json('error.message'))->toContain('cursor');
});

it('keeps every recipe tool registered when the launcher lineup is restricted', function () {
    $token = mcpPaginationToken();

    config(['launcher.lineup' => 'unreal-tournament-2004']);

    $collected = mcpCollectTools($token, 100);

    expect($collected['names'])->toBe(mcpPaginationToolNames())
        ->and($collected['names'])->toContain(
            'recipes_list',
            'recipe_get',
            'recipe_install_plan',
            'recipe_avatars',
            'recipe_contribute',
        );
});
