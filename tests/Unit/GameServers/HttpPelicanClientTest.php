<?php

use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\HttpPelicanClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

it('creates a server and maps the panel response', function () {
    Http::fake([
        'panel.test/api/application/servers' => Http::response([
            'object' => 'server',
            'attributes' => [
                'id' => 42,
                'uuid' => 'abc-123',
                'status' => null,
                'allocation' => [
                    'ip' => '10.0.0.5',
                    'port' => 27015,
                ],
            ],
        ], 201),
    ]);

    $server = (new HttpPelicanClient('http://panel.test', 'app-token', null))
        ->createServer('5', ['name' => 'CS2 Server', 'allocation' => ['default' => 1]]);

    expect($server->id)->toBe('42')
        ->and($server->state)->toBe(ServerState::Running)
        ->and($server->address)->toBe('10.0.0.5')
        ->and($server->port)->toBe(27015);

    Http::assertSent(fn ($request) => $request->url() === 'http://panel.test/api/application/servers'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer app-token')
        && $request['egg_id'] === '5'
        && $request['name'] === 'CS2 Server');
});

it('includes the configured nodeId in the create payload when set', function () {
    Http::fake([
        'panel.test/api/application/servers' => Http::response([
            'object' => 'server',
            'attributes' => [
                'id' => 42,
                'uuid' => 'abc-123',
                'status' => 'installing',
                'allocation' => null,
            ],
        ], 201),
    ]);

    (new HttpPelicanClient('http://panel.test', 'app-token', '9'))
        ->createServer('5', ['name' => 'CS2 Server']);

    Http::assertSent(fn ($request) => $request['node_id'] === '9');
});

it('lets an explicit nodeId argument override the configured default', function () {
    Http::fake([
        'panel.test/api/application/servers' => Http::response([
            'object' => 'server',
            'attributes' => ['id' => 42, 'uuid' => 'abc-123', 'status' => 'installing', 'allocation' => null],
        ], 201),
    ]);

    (new HttpPelicanClient('http://panel.test', 'app-token', '9'))
        ->createServer('5', ['name' => 'CS2 Server'], '3');

    Http::assertSent(fn ($request) => $request['node_id'] === '3');
});

it('maps installing status to the Installing state', function () {
    Http::fake([
        'panel.test/api/application/servers/42' => Http::response([
            'object' => 'server',
            'attributes' => ['id' => 42, 'uuid' => 'abc-123', 'status' => 'installing', 'allocation' => null],
        ], 200),
    ]);

    $server = (new HttpPelicanClient('http://panel.test', 'app-token', null))->getServer('42');

    expect($server->state)->toBe(ServerState::Installing);
});

it('maps a null status to the Running state', function () {
    Http::fake([
        'panel.test/api/application/servers/42' => Http::response([
            'object' => 'server',
            'attributes' => ['id' => 42, 'uuid' => 'abc-123', 'status' => null, 'allocation' => null],
        ], 200),
    ]);

    $server = (new HttpPelicanClient('http://panel.test', 'app-token', null))->getServer('42');

    expect($server->state)->toBe(ServerState::Running);
});

it('maps a suspended status to the Failed state', function () {
    Http::fake([
        'panel.test/api/application/servers/42' => Http::response([
            'object' => 'server',
            'attributes' => ['id' => 42, 'uuid' => 'abc-123', 'status' => 'suspended', 'allocation' => null],
        ], 200),
    ]);

    $server = (new HttpPelicanClient('http://panel.test', 'app-token', null))->getServer('42');

    expect($server->state)->toBe(ServerState::Failed);
});

it('sends a power action to the client API power endpoint', function () {
    Http::fake([
        'panel.test/api/client/servers/abc-123/power' => Http::response(null, 204),
    ]);

    (new HttpPelicanClient('http://panel.test', 'app-token', null))
        ->powerAction('abc-123', PowerAction::Restart);

    Http::assertSent(fn ($request) => $request->url() === 'http://panel.test/api/client/servers/abc-123/power'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer app-token')
        && $request['signal'] === 'restart');
});

it('deletes a server via the application API', function () {
    Http::fake([
        'panel.test/api/application/servers/42' => Http::response(null, 204),
    ]);

    (new HttpPelicanClient('http://panel.test', 'app-token', null))->deleteServer('42');

    Http::assertSent(fn ($request) => $request->url() === 'http://panel.test/api/application/servers/42'
        && $request->method() === 'DELETE'
        && $request->hasHeader('Authorization', 'Bearer app-token'));
});

it('throws when creating a server fails', function () {
    Http::fake([
        'panel.test/api/application/servers' => Http::response(['errors' => [['detail' => 'unauthorized']]], 401),
    ]);

    (new HttpPelicanClient('http://panel.test', 'bad-token', null))->createServer('5', []);
})->throws(RequestException::class);

it('retries once after a transient 500 and then succeeds', function () {
    Http::fake([
        'panel.test/api/application/servers' => Http::sequence()
            ->push(['errors' => [['detail' => 'Internal Server Error']]], 500)
            ->push([
                'object' => 'server',
                'attributes' => ['id' => 42, 'uuid' => 'abc-123', 'status' => 'installing', 'allocation' => null],
            ], 201),
    ]);

    (new HttpPelicanClient('http://panel.test', 'app-token', null))->createServer('5', []);

    Http::assertSentCount(2);
});

it('does not retry a 404 client error and throws immediately', function () {
    Http::fake([
        'panel.test/api/application/servers/999' => Http::response(['errors' => [['detail' => 'Not Found']]], 404),
    ]);

    expect(fn () => (new HttpPelicanClient('http://panel.test', 'app-token', null))->getServer('999'))
        ->toThrow(RequestException::class);

    Http::assertSentCount(1);
});
