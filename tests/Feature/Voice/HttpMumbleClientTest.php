<?php

use App\Modules\Voice\HttpMumbleClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

it('creates a channel and maps the sidecar response', function () {
    Http::fake([
        'mumble-admin.test/channels' => Http::response([
            'id' => 12,
            'name' => 'match-1',
            'parent' => 0,
            'temporary' => false,
        ], 201),
    ]);

    $channel = (new HttpMumbleClient('http://mumble-admin.test', 'secret-token'))->createChannel('match-1');

    expect($channel->id)->toBe(12)
        ->and($channel->name)->toBe('match-1')
        ->and($channel->parentId)->toBeNull()
        ->and($channel->temporary)->toBeFalse();

    Http::assertSent(fn ($request) => $request->url() === 'http://mumble-admin.test/channels'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request['name'] === 'match-1'
        && $request['parent'] === 0
        && $request['temporary'] === false);
});

it('creates a channel with a parent and temporary flag', function () {
    Http::fake([
        'mumble-admin.test/channels' => Http::response([
            'id' => 13,
            'name' => 'sub-room',
            'parent' => 5,
            'temporary' => true,
            'occupants' => 3,
        ], 201),
    ]);

    $channel = (new HttpMumbleClient('http://mumble-admin.test', 'secret-token'))
        ->createChannel('sub-room', 5, true);

    expect($channel->parentId)->toBe(5)
        ->and($channel->temporary)->toBeTrue()
        ->and($channel->occupants)->toBe(3);

    Http::assertSent(fn ($request) => $request['parent'] === 5
        && $request['temporary'] === true);
});

it('defaults occupants to zero when absent from the response', function () {
    Http::fake([
        'mumble-admin.test/channels' => Http::response([
            'id' => 14,
            'name' => 'no-occupants-field',
            'parent' => 0,
            'temporary' => false,
        ], 201),
    ]);

    $channel = (new HttpMumbleClient('http://mumble-admin.test', 'secret-token'))
        ->createChannel('no-occupants-field');

    expect($channel->occupants)->toBe(0);
});

it('throws when creating a channel fails', function () {
    Http::fake([
        'mumble-admin.test/channels' => Http::response(['detail' => 'unauthorized'], 401),
    ]);

    (new HttpMumbleClient('http://mumble-admin.test', 'bad-token'))->createChannel('match-1');
})->throws(RequestException::class);

it('renames a channel via PATCH', function () {
    Http::fake([
        'mumble-admin.test/channels/12' => Http::response([
            'id' => 12,
            'name' => 'renamed',
            'parent' => 0,
            'temporary' => false,
        ], 200),
    ]);

    (new HttpMumbleClient('http://mumble-admin.test', 'secret-token'))->renameChannel(12, 'renamed');

    Http::assertSent(fn ($request) => $request->url() === 'http://mumble-admin.test/channels/12'
        && $request->method() === 'PATCH'
        && $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request['name'] === 'renamed');
});

it('throws when renaming a channel fails', function () {
    Http::fake([
        'mumble-admin.test/channels/12' => Http::response(['detail' => 'unknown channel id'], 404),
    ]);

    (new HttpMumbleClient('http://mumble-admin.test', 'secret-token'))->renameChannel(12, 'renamed');
})->throws(RequestException::class);

it('deletes a channel via DELETE', function () {
    Http::fake([
        'mumble-admin.test/channels/12' => Http::response(null, 204),
    ]);

    (new HttpMumbleClient('http://mumble-admin.test', 'secret-token'))->deleteChannel(12);

    Http::assertSent(fn ($request) => $request->url() === 'http://mumble-admin.test/channels/12'
        && $request->method() === 'DELETE'
        && $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('throws when deleting a channel fails', function () {
    Http::fake([
        'mumble-admin.test/channels/12' => Http::response(['detail' => 'unknown channel id'], 404),
    ]);

    (new HttpMumbleClient('http://mumble-admin.test', 'secret-token'))->deleteChannel(12);
})->throws(RequestException::class);

it('lists channels and maps each to a VoiceChannel', function () {
    Http::fake([
        'mumble-admin.test/channels' => Http::response([
            ['id' => 0, 'name' => 'Root', 'parent' => 0, 'temporary' => false, 'occupants' => 5],
            ['id' => 12, 'name' => 'match-1', 'parent' => 0, 'temporary' => false, 'occupants' => 2],
        ], 200),
    ]);

    $channels = (new HttpMumbleClient('http://mumble-admin.test', 'secret-token'))->listChannels();

    expect($channels)->toHaveCount(2)
        ->and($channels[1]->id)->toBe(12)
        ->and($channels[1]->name)->toBe('match-1')
        ->and($channels[1]->occupants)->toBe(2);

    Http::assertSent(fn ($request) => $request->url() === 'http://mumble-admin.test/channels'
        && $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('throws when listing channels fails', function () {
    Http::fake([
        'mumble-admin.test/channels' => Http::response(['detail' => 'bad gateway'], 502),
    ]);

    (new HttpMumbleClient('http://mumble-admin.test', 'secret-token'))->listChannels();
})->throws(RequestException::class);

it('retries once after a transient 500 and then succeeds', function () {
    Http::fake([
        'mumble-admin.test/channels' => Http::sequence()
            ->push(['detail' => 'Internal Server Error'], 500)
            ->push([
                'id' => 12,
                'name' => 'match-1',
                'parent' => 0,
                'temporary' => false,
            ], 201),
    ]);

    (new HttpMumbleClient('http://mumble-admin.test', 'secret-token'))->createChannel('match-1');

    Http::assertSentCount(2);
});

it('does not retry a 404 client error and throws immediately', function () {
    Http::fake([
        'mumble-admin.test/channels/12' => Http::response(['detail' => 'unknown channel id'], 404),
    ]);

    expect(fn () => (new HttpMumbleClient('http://mumble-admin.test', 'secret-token'))->deleteChannel(12))
        ->toThrow(RequestException::class);

    Http::assertSentCount(1);
});
