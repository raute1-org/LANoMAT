<?php

use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Domain\ServerLimits;
use App\Modules\GameServers\Domain\ServerSpec;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\HttpPelicanClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Response shapes below mirror a real Pelican panel (verified live); hosts, ips,
// uuids and tokens are placeholders.

const PANEL = 'https://pelican.example.test';

function pelicanClient(?string $node = '1', string $app = 'papp_FAKE_APPLICATION', string $client = 'pacc_FAKE_CLIENT', string $url = PANEL): HttpPelicanClient
{
    return new HttpPelicanClient($url, $app, $client, $node);
}

function pelicanSpec(?string $allocationId = null, ?string $nodeId = null, array $environment = ['VANILLA_VERSION' => 'latest']): ServerSpec
{
    return new ServerSpec('lanomat-test', '3', '4', $environment, new ServerLimits(memory: 1024, disk: 2048, cpu: 100), $allocationId, $nodeId, startOnCompletion: false);
}

/** @return array<string, mixed> */
function pelicanServerAttributes(array $override = []): array
{
    return array_merge([
        'id' => 12,
        'external_id' => null,
        'uuid' => '81ec06ec-0000-4000-8000-000000000000',
        'identifier' => '81ec06ec',
        'name' => 'lanomat-test',
        'description' => '',
        'status' => null,
        'suspended' => false,
        'limits' => ['memory' => 1024, 'swap' => 0, 'disk' => 2048, 'io' => 500, 'cpu' => 100, 'threads' => null, 'oom_disabled' => true],
        'feature_limits' => ['databases' => 0, 'allocations' => 1, 'backups' => 0],
        'user' => 3,
        'node' => 1,
        'allocation' => 2,
        'egg' => 4,
        'container' => ['startup_command' => 'java -jar server.jar', 'image' => 'ghcr.io/example/yolks:java_21', 'installed' => 1, 'environment' => []],
        'created_at' => '2026-01-01T00:00:00+00:00',
        'updated_at' => '2026-01-01T00:00:00+00:00',
    ], $override);
}

function pelicanAllocation(int $id, int $port, bool $assigned = false, ?string $alias = null): array
{
    return ['object' => 'allocation', 'attributes' => ['id' => $id, 'ip' => '192.0.2.10', 'alias' => $alias, 'port' => $port, 'notes' => null, 'assigned' => $assigned]];
}

function pelicanList(array $data, ?array $pagination = ['current_page' => 1, 'total_pages' => 1]): array
{
    $list = ['object' => 'list', 'data' => $data];

    if ($pagination !== null) {
        $list['meta'] = ['pagination' => array_merge(['total' => count($data), 'count' => count($data), 'per_page' => 100, 'links' => []], $pagination)];
    }

    return $list;
}

function pelicanResources(string $state, bool $suspended = false): array
{
    return ['object' => 'stats', 'attributes' => ['current_state' => $state, 'is_suspended' => $suspended, 'resources' => [
        'memory_bytes' => 1048576, 'cpu_absolute' => 1.5, 'disk_bytes' => 2097152, 'network_rx_bytes' => 0, 'network_tx_bytes' => 0, 'uptime' => 5000,
    ]]];
}

function pelicanErrors(string ...$details): array
{
    return ['errors' => array_map(fn (string $d): array => ['code' => 'ValidationException', 'status' => '422', 'detail' => $d, 'meta' => []], $details)];
}

it('creates a server on the first free allocation with the documented application payload', function () {
    Http::fake([
        'pelican.example.test/api/application/nodes/1/allocations*' => Http::response(pelicanList([
            pelicanAllocation(1, 25565, assigned: true),
            pelicanAllocation(2, 27015),
        ])),
        'pelican.example.test/api/application/servers' => Http::response(['object' => 'server', 'attributes' => pelicanServerAttributes()], 201),
    ]);

    $server = pelicanClient()->createServer(pelicanSpec());

    expect($server->id)->toBe('12')
        ->and($server->identifier)->toBe('81ec06ec')
        ->and($server->uuid)->toBe('81ec06ec-0000-4000-8000-000000000000')
        ->and($server->name)->toBe('lanomat-test')
        ->and($server->nodeId)->toBe('1')
        ->and($server->allocationId)->toBe('2')
        ->and($server->address)->toBe('192.0.2.10')
        ->and($server->port)->toBe(27015)
        ->and($server->state)->toBe(ServerState::Unknown)
        ->and($server->limits->memory)->toBe(1024);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === PANEL.'/api/application/servers'
        && $r->hasHeader('Authorization', 'Bearer papp_FAKE_APPLICATION')
        && $r['name'] === 'lanomat-test'
        && $r['user'] === 3
        && $r['egg'] === 4
        && $r['environment'] === ['VANILLA_VERSION' => 'latest']
        && $r['limits'] === ['memory' => 1024, 'swap' => 0, 'disk' => 2048, 'io' => 500, 'cpu' => 100]
        && $r['feature_limits'] === ['databases' => 0, 'backups' => 0]
        && $r['allocation'] === ['default' => 2]
        && $r['start_on_completion'] === false);
});

it('resolves an explicit allocation id to address and port through the node allocations', function () {
    Http::fake([
        'pelican.example.test/api/application/nodes/1/allocations*' => Http::response(pelicanList([pelicanAllocation(2, 27015, alias: 'game.example.test')])),
        'pelican.example.test/api/application/servers' => Http::response(['object' => 'server', 'attributes' => pelicanServerAttributes()], 201),
    ]);

    $server = pelicanClient(node: null)->createServer(pelicanSpec(allocationId: '2', nodeId: '1'));

    expect($server->address)->toBe('game.example.test')->and($server->port)->toBe(27015);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['allocation'] === ['default' => 2]);
});

it('encodes an empty environment as a JSON object', function () {
    Http::fake([
        'pelican.example.test/api/application/nodes/1/allocations*' => Http::response(pelicanList([pelicanAllocation(2, 27015)])),
        'pelican.example.test/api/application/servers' => Http::response(['object' => 'server', 'attributes' => pelicanServerAttributes()], 201),
    ]);

    pelicanClient()->createServer(pelicanSpec(environment: []));

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->body(), '"environment":{}'));
});

it('fails before posting when the node has no free allocation', function () {
    Http::fake([
        'pelican.example.test/api/application/nodes/1/allocations*' => Http::response(pelicanList([pelicanAllocation(1, 25565, assigned: true)])),
    ]);

    expect(fn () => pelicanClient()->createServer(pelicanSpec()))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.no_free_allocation');

    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('fails when neither an allocation nor a node is known', function () {
    Http::fake();

    expect(fn () => pelicanClient(node: null)->createServer(pelicanSpec()))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.no_node_configured');
});

it('maps a 422 on create to a rejected domain exception carrying the panel details', function () {
    Http::fake([
        'pelican.example.test/api/application/servers' => Http::response(pelicanErrors('The environment.VANILLA_VERSION field is required.', 'The egg is invalid.'), 422),
    ]);

    try {
        pelicanClient()->createServer(pelicanSpec(allocationId: '2', nodeId: '1'));
        test()->fail('expected exception');
    } catch (GameServerException $e) {
        expect($e->translationKey)->toBe('gameservers.errors.pelican_rejected')
            ->and($e->httpStatus)->toBe(422)
            ->and($e->details)->toBe(['The environment.VANILLA_VERSION field is required.', 'The egg is invalid.'])
            ->and(__($e->translationKey))->toContain('Pelican');
    }
});

it('never retries a create, even on a 5xx', function () {
    Http::fake([
        'pelican.example.test/api/application/nodes/1/allocations*' => Http::response(pelicanList([pelicanAllocation(2, 27015)])),
        'pelican.example.test/api/application/servers' => Http::sequence()
            ->push(pelicanErrors('boom'), 500)
            ->push(['object' => 'server', 'attributes' => pelicanServerAttributes()], 201),
    ]);

    expect(fn () => pelicanClient()->createServer(pelicanSpec()))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.pelican_unavailable' && $e->httpStatus === 500);

    Http::assertSentCount(2); // allocations + exactly one POST
});

it('shows an installing server without asking the client api', function () {
    Http::fake([
        'pelican.example.test/api/application/servers/12*' => Http::response(['object' => 'server', 'attributes' => pelicanServerAttributes(['status' => 'installing'])]),
        'pelican.example.test/api/application/nodes/1/allocations*' => Http::response(pelicanList([pelicanAllocation(2, 27015)])),
    ]);

    $server = pelicanClient()->getServer('12');

    expect($server->state)->toBe(ServerState::Installing)->and($server->address)->toBe('192.0.2.10');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/api/client/'));
});

it('merges the real runtime state from the client api once the application status is null', function (string $current, ServerState $expected) {
    Http::fake([
        'pelican.example.test/api/application/servers/12*' => Http::response(['object' => 'server', 'attributes' => pelicanServerAttributes()]),
        'pelican.example.test/api/application/nodes/1/allocations*' => Http::response(pelicanList([pelicanAllocation(2, 27015)])),
        'pelican.example.test/api/client/servers/81ec06ec/resources' => Http::response(pelicanResources($current)),
    ]);

    expect(pelicanClient()->getServer('12')->state)->toBe($expected);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/client/servers/81ec06ec/resources')
        && $r->hasHeader('Authorization', 'Bearer pacc_FAKE_CLIENT'));
})->with([
    'running' => ['running', ServerState::Running],
    'offline' => ['offline', ServerState::Stopped],
    'starting' => ['starting', ServerState::Starting],
    'stopping' => ['stopping', ServerState::Stopping],
]);

it('reads the allocation from ?include=allocations without a second lookup', function () {
    $attributes = pelicanServerAttributes(['status' => 'installing']);
    $attributes['relationships'] = ['allocations' => pelicanList([pelicanAllocation(2, 27015), pelicanAllocation(3, 27016)], null)];

    Http::fake([
        'pelican.example.test/api/application/servers/12*' => Http::response(['object' => 'server', 'attributes' => $attributes]),
    ]);

    $server = pelicanClient()->getServer('12');

    expect($server->port)->toBe(27015);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'include=allocations'));
    Http::assertSentCount(1);
});

it('treats a 409 from the client api as still installing', function () {
    Http::fake([
        'pelican.example.test/api/application/servers/12*' => Http::response(['object' => 'server', 'attributes' => pelicanServerAttributes()]),
        'pelican.example.test/api/application/nodes/1/allocations*' => Http::response(pelicanList([pelicanAllocation(2, 27015)])),
        'pelican.example.test/api/client/servers/81ec06ec/resources' => Http::response(pelicanErrors('Server is installing'), 409),
    ]);

    expect(pelicanClient()->getServer('12')->state)->toBe(ServerState::Installing);
});

it('explains a 404 from the client api as an ownership problem', function () {
    Http::fake([
        'pelican.example.test/api/application/servers/12*' => Http::response(['object' => 'server', 'attributes' => pelicanServerAttributes()]),
        'pelican.example.test/api/application/nodes/1/allocations*' => Http::response(pelicanList([pelicanAllocation(2, 27015)])),
        'pelican.example.test/api/client/servers/81ec06ec/resources' => Http::response(['errors' => []], 404),
    ]);

    expect(fn () => pelicanClient()->getServer('12'))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.pelican_not_found'
            && str_contains($e->getMessage(), 'must own'));
});

it('maps a suspended server to Failed', function () {
    Http::fake([
        'pelican.example.test/api/application/servers/12*' => Http::response(['object' => 'server', 'attributes' => pelicanServerAttributes(['suspended' => true, 'status' => 'suspended'])]),
        'pelican.example.test/api/application/nodes/1/allocations*' => Http::response(pelicanList([pelicanAllocation(2, 27015)])),
    ]);

    expect(pelicanClient()->getServer('12')->state)->toBe(ServerState::Failed);
});

it('sends power signals to the client api by identifier with the client token', function (PowerAction $action) {
    Http::fake(['pelican.example.test/api/client/servers/81ec06ec/power' => Http::response(null, 204)]);

    pelicanClient()->powerAction('81ec06ec', $action);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === PANEL.'/api/client/servers/81ec06ec/power'
        && $r->hasHeader('Authorization', 'Bearer pacc_FAKE_CLIENT')
        && $r['signal'] === $action->value);
})->with(PowerAction::cases());

it('reads runtime stats from the client api resources endpoint', function () {
    Http::fake(['pelican.example.test/api/client/servers/81ec06ec/resources' => Http::response(pelicanResources('running'))]);

    $stats = pelicanClient()->getResources('81ec06ec');

    expect($stats->state)->toBe(ServerState::Running)
        ->and($stats->memoryBytes)->toBe(1048576)
        ->and($stats->cpuAbsolute)->toBe(1.5)
        ->and($stats->diskBytes)->toBe(2097152)
        ->and($stats->uptimeMs)->toBe(5000)
        ->and($stats->suspended)->toBeFalse();
});

it('deletes a server via the application api and treats an already-deleted one as success', function () {
    Http::fake([
        'pelican.example.test/api/application/servers/12' => Http::response(null, 204),
        'pelican.example.test/api/application/servers/13' => Http::response(['errors' => []], 404),
    ]);

    pelicanClient()->deleteServer('12');
    pelicanClient()->deleteServer('13');

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->hasHeader('Authorization', 'Bearer papp_FAKE_APPLICATION'));
});

it('surfaces a 403 on delete as an unauthorized domain exception', function () {
    Http::fake(['pelican.example.test/api/application/servers/12' => Http::response(['errors' => []], 403)]);

    expect(fn () => pelicanClient()->deleteServer('12'))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.pelican_unauthorized' && $e->httpStatus === 403);
});

it('follows pagination when listing servers', function () {
    Http::fake([
        'pelican.example.test/api/application/servers*' => Http::sequence()
            ->push(pelicanList([['object' => 'server', 'attributes' => pelicanServerAttributes(['id' => 1])]], ['current_page' => 1, 'total_pages' => 2]))
            ->push(pelicanList([['object' => 'server', 'attributes' => pelicanServerAttributes(['id' => 2])]], ['current_page' => 2, 'total_pages' => 2])),
    ]);

    $servers = pelicanClient()->listServers();

    expect(array_map(fn ($s) => $s->id, $servers))->toBe(['1', '2'])
        ->and($servers[0]->state)->toBe(ServerState::Unknown);
    Http::assertSentCount(2);
});

it('lists nodes', function () {
    Http::fake(['pelican.example.test/api/application/nodes*' => Http::response(pelicanList([
        ['object' => 'node', 'attributes' => ['id' => 1, 'name' => 'node-a', 'fqdn' => 'node.example.test', 'maintenance_mode' => true, 'memory' => 6144, 'disk' => 100000]],
    ]))]);

    $nodes = pelicanClient()->listNodes();

    expect($nodes)->toHaveCount(1)
        ->and($nodes[0]->id)->toBe('1')
        ->and($nodes[0]->fqdn)->toBe('node.example.test')
        ->and($nodes[0]->maintenanceMode)->toBeTrue()
        ->and($nodes[0]->memoryMb)->toBe(6144);
});

it('lists eggs from a response that has no pagination meta', function () {
    Http::fake(['pelican.example.test/api/application/eggs*' => Http::response(pelicanList([
        ['object' => 'egg', 'attributes' => ['id' => 3, 'name' => 'Paper']],
        ['object' => 'egg', 'attributes' => ['id' => 4, 'name' => 'Vanilla Minecraft']],
    ], null))]);

    $eggs = pelicanClient()->listEggs();

    expect(array_map(fn ($e) => $e->name, $eggs))->toBe(['Paper', 'Vanilla Minecraft']);
    Http::assertSentCount(1);
});

it('reads an egg with its variables, defaults and required flags', function () {
    Http::fake(['pelican.example.test/api/application/eggs/4*' => Http::response(['object' => 'egg', 'attributes' => [
        'id' => 4, 'name' => 'Vanilla Minecraft',
        'relationships' => ['variables' => pelicanList([
            ['object' => 'egg_variable', 'attributes' => ['env_variable' => 'VANILLA_VERSION', 'default_value' => 'latest', 'rules' => ['required', 'string']]],
            ['object' => 'egg_variable', 'attributes' => ['env_variable' => 'MOTD', 'default_value' => null, 'rules' => ['nullable']]],
        ], null)],
    ]])]);

    $egg = pelicanClient()->getEgg('4');

    expect($egg->variables)->toBe(['VANILLA_VERSION' => 'latest', 'MOTD' => ''])
        ->and($egg->requiredVariables)->toBe(['VANILLA_VERSION']);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'include=variables'));
});

it('maps 401 and 403 to unauthorized without leaking the token', function (int $status) {
    Http::fake(['pelican.example.test/api/application/nodes*' => Http::response(pelicanErrors('Unauthenticated.'), $status)]);

    try {
        pelicanClient(app: 'papp_FAKE_SECRET')->listNodes();
        test()->fail('expected exception');
    } catch (GameServerException $e) {
        expect($e->translationKey)->toBe('gameservers.errors.pelican_unauthorized')
            ->and($e->getMessage())->not->toContain('papp_FAKE_SECRET');
    }

    Http::assertSentCount(1);
})->with([401, 403]);

it('retries a transient 500 on reads and then succeeds', function () {
    Http::fake(['pelican.example.test/api/application/nodes*' => Http::sequence()
        ->push(pelicanErrors('boom'), 500)
        ->push(pelicanList([]))]);

    expect(pelicanClient()->listNodes())->toBe([]);
    Http::assertSentCount(2);
});

it('gives up with an unavailable exception after the retry budget', function () {
    Http::fake(['pelican.example.test/api/application/nodes*' => Http::response(pelicanErrors('boom'), 503)]);

    expect(fn () => pelicanClient()->listNodes())
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.pelican_unavailable' && $e->httpStatus === 503);

    Http::assertSentCount(3);
});

it('maps a connection failure to unavailable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

    expect(fn () => pelicanClient()->listNodes())
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.pelican_unavailable' && $e->httpStatus === null);
});

it('does not retry a 404', function () {
    Http::fake(['pelican.example.test/api/application/servers/999*' => Http::response(['errors' => []], 404)]);

    expect(fn () => pelicanClient()->getServer('999'))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.pelican_not_found');

    Http::assertSentCount(1);
});

it('fails gracefully when unconfigured instead of calling out', function (string $url, string $app, string $client, string $call) {
    Http::fake();
    $c = pelicanClient(app: $app, client: $client, url: $url);

    expect(fn () => $call === 'app' ? $c->listNodes() : $c->powerAction('81ec06ec', PowerAction::Start))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.pelican_not_configured');

    Http::assertNothingSent();
})->with([
    'no url' => ['', 'papp_x', 'pacc_x', 'app'],
    'no application token' => [PANEL, '', 'pacc_x', 'app'],
    'no client token' => [PANEL, 'papp_x', '', 'client'],
]);
