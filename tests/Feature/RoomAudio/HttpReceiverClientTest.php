<?php

declare(strict_types=1);

use App\Modules\RoomAudio\Exceptions\ReceiverUnavailable;
use App\Modules\RoomAudio\HttpReceiverClient;
use App\Modules\RoomAudio\Support\ReceiverState;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function receiverClient(): HttpReceiverClient
{
    return new HttpReceiverClient('http://denon-admin.test', 'secret-token');
}

/**
 * @return array<string, mixed>
 */
function receiverStatePayload(): array
{
    return [
        'connected' => true,
        'power' => true,
        'mainZone' => true,
        'volume' => 32.5,
        'volumeMax' => 98,
        'mute' => false,
        'source' => 'DVD',
        'mode' => 'stereo',
        'modeName' => 'Stereo',
        'sources' => [
            ['code' => 'DVD', 'name' => 'Blu-ray'],
            ['code' => 'HDMI1', 'name' => 'Game Console'],
        ],
        'modes' => [
            ['key' => 'stereo', 'name' => 'Stereo'],
            ['key' => 'direct', 'name' => 'Direct'],
        ],
    ];
}

it('maps the sidecar state payload', function () {
    Http::fake([
        'denon-admin.test/state' => Http::response(receiverStatePayload(), 200),
    ]);

    $state = receiverClient()->state();

    expect($state->connected)->toBeTrue()
        ->and($state->power)->toBeTrue()
        ->and($state->volume)->toBe(32.5)
        ->and($state->volumeMax)->toBe(98.0)
        ->and($state->mute)->toBeFalse()
        ->and($state->source)->toBe('DVD')
        ->and($state->mode)->toBe('stereo')
        ->and($state->modeName)->toBe('Stereo')
        ->and($state->sources)->toBe([
            ['code' => 'DVD', 'name' => 'Blu-ray'],
            ['code' => 'HDMI1', 'name' => 'Game Console'],
        ])
        ->and($state->modes)->toBe([
            ['key' => 'stereo', 'name' => 'Stereo'],
            ['key' => 'direct', 'name' => 'Direct'],
        ]);

    Http::assertSent(fn ($request) => $request->url() === 'http://denon-admin.test/state'
        && $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('maps a null volume to null', function () {
    $payload = receiverStatePayload();
    $payload['volume'] = null;
    Http::fake([
        'denon-admin.test/state' => Http::response($payload, 200),
    ]);

    expect(receiverClient()->state()->volume)->toBeNull();
});

it('returns an unreachable state when the connection fails', function () {
    Http::fake([
        'denon-admin.test/state' => Http::failedConnection(),
    ]);

    $state = receiverClient()->state();

    expect($state)->toEqual(ReceiverState::unreachable())
        ->and($state->connected)->toBeFalse();
});

it('returns an unreachable state on 503 instead of throwing', function () {
    Http::fake([
        'denon-admin.test/state' => Http::response(['detail' => 'receiver not connected'], 503),
    ]);

    expect(receiverClient()->state()->connected)->toBeFalse();
});

it('returns an unreachable state on 401 instead of throwing', function () {
    Http::fake([
        'denon-admin.test/state' => Http::response(['detail' => 'invalid or missing bearer token'], 401),
    ]);

    expect(receiverClient()->state()->connected)->toBeFalse();
});

it('sends power as on/off with the bearer token', function (bool $on, string $value) {
    Http::fake([
        'denon-admin.test/command' => Http::response(['ok' => true], 200),
    ]);

    receiverClient()->setPower($on);

    Http::assertSent(fn ($request) => $request->url() === 'http://denon-admin.test/command'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request['command'] === 'power'
        && $request['value'] === $value);
})->with([
    'on' => [true, 'on'],
    'off' => [false, 'off'],
]);

it('sends the volume as a number', function () {
    Http::fake([
        'denon-admin.test/command' => Http::response(['ok' => true], 200),
    ]);

    receiverClient()->setVolume(32.5);

    Http::assertSent(fn ($request) => $request['command'] === 'volume'
        && $request['value'] === 32.5);
});

it('sends mute as on/off', function (bool $mute, string $value) {
    Http::fake([
        'denon-admin.test/command' => Http::response(['ok' => true], 200),
    ]);

    receiverClient()->setMute($mute);

    Http::assertSent(fn ($request) => $request['command'] === 'mute'
        && $request['value'] === $value);
})->with([
    'muted' => [true, 'on'],
    'unmuted' => [false, 'off'],
]);

it('sends the source code', function () {
    Http::fake([
        'denon-admin.test/command' => Http::response(['ok' => true], 200),
    ]);

    receiverClient()->setSource('HDMI1');

    Http::assertSent(fn ($request) => $request['command'] === 'source'
        && $request['value'] === 'HDMI1');
});

it('sends the sound mode key', function () {
    Http::fake([
        'denon-admin.test/command' => Http::response(['ok' => true], 200),
    ]);

    receiverClient()->setMode('direct');

    Http::assertSent(fn ($request) => $request['command'] === 'mode'
        && $request['value'] === 'direct');
});

it('throws ReceiverUnavailable when a setter cannot connect', function () {
    Http::fake([
        'denon-admin.test/command' => Http::failedConnection(),
    ]);

    receiverClient()->setVolume(32.5);
})->throws(ReceiverUnavailable::class);

it('throws ReceiverUnavailable when a setter gets a 503', function () {
    Http::fake([
        'denon-admin.test/command' => Http::response(['detail' => 'receiver not connected'], 503),
    ]);

    receiverClient()->setPower(true);
})->throws(ReceiverUnavailable::class);

it('does not swallow a 422 from a setter', function () {
    Http::fake([
        'denon-admin.test/command' => Http::response(['detail' => 'volume out of range'], 422),
    ]);

    receiverClient()->setVolume(99.0);
})->throws(RequestException::class);

it('does not retry a setter on a transient failure', function () {
    Http::fake([
        'denon-admin.test/command' => Http::sequence()
            ->push(['detail' => 'busy'], 503)
            ->push(['ok' => true], 200),
    ]);

    try {
        receiverClient()->setVolume(32.5);
    } catch (ReceiverUnavailable) {
    }

    Http::assertSentCount(1);
});

it('retries state() on a transient failure before giving up', function () {
    Http::fake([
        'denon-admin.test/state' => Http::sequence()
            ->push(['detail' => 'busy'], 503)
            ->push(receiverStatePayload(), 200),
    ]);

    expect(receiverClient()->state()->connected)->toBeTrue();

    Http::assertSentCount(2);
});

it('returns an unreachable state when the sidecar answers 200 with a non-object JSON body', function () {
    Http::fake([
        'denon-admin.test/state' => Http::response('"not an object"', 200, ['Content-Type' => 'application/json']),
    ]);

    expect(receiverClient()->state())->toEqual(ReceiverState::unreachable());
});

it('configures connect and total timeouts', function () {
    $client = receiverClient();
    $pending = (new ReflectionMethod($client, 'http'))->invoke($client);
    $options = (fn () => $this->options)->call($pending);

    expect($options['connect_timeout'])->toBe(2)
        ->and($options['timeout'])->toBe(5);
});

it('tolerates a trailing slash on the base URL', function () {
    Http::fake([
        'denon-admin.test/*' => Http::response(receiverStatePayload(), 200),
    ]);

    $client = new HttpReceiverClient('http://denon-admin.test/', 'secret-token');

    expect($client->state()->connected)->toBeTrue();
    $client->setMute(true);

    Http::assertSent(fn ($request) => $request->url() === 'http://denon-admin.test/state');
    Http::assertSent(fn ($request) => $request->url() === 'http://denon-admin.test/command');
});
