<?php

declare(strict_types=1);

use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\HttpTeamSpeakClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function tsClient(): HttpTeamSpeakClient
{
    return new HttpTeamSpeakClient('http://ts-admin.test', 'secret-token');
}

it('creates a channel and maps the sidecar response', function () {
    Http::fake([
        'ts-admin.test/channels' => Http::response([
            'id' => 12,
            'name' => 'match-1',
            'parent' => 0,
            'temporary' => false,
            'occupants' => 0,
        ], 201),
    ]);

    $channel = tsClient()->createChannel('match-1');

    expect($channel->id)->toBe(12)
        ->and($channel->name)->toBe('match-1')
        ->and($channel->parentId)->toBeNull()
        ->and($channel->temporary)->toBeFalse()
        ->and($channel->occupants)->toBe(0);

    Http::assertSent(fn ($request) => $request->url() === 'http://ts-admin.test/channels'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request['name'] === 'match-1'
        && $request['parent'] === 0
        && $request['temporary'] === false);
});

it('creates a channel and maps root parent to null', function () {
    Http::fake([
        'ts-admin.test/channels' => Http::response(
            ['id' => 7, 'name' => '🏆 Cup', 'parent' => 0, 'temporary' => false, 'occupants' => 0],
            201,
        ),
    ]);

    $channel = tsClient()->createChannel('🏆 Cup');

    expect($channel->id)->toBe(7)
        ->and($channel->parentId)->toBeNull()
        ->and($channel->name)->toBe('🏆 Cup');

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer secret-token')
        && $r['name'] === '🏆 Cup' && $r['parent'] === 0);
});

it('creates a channel with a parent and temporary flag', function () {
    Http::fake([
        'ts-admin.test/channels' => Http::response([
            'id' => 13,
            'name' => 'sub-room',
            'parent' => 5,
            'temporary' => true,
            'occupants' => 3,
        ], 201),
    ]);

    $channel = tsClient()->createChannel('sub-room', 5, true);

    expect($channel->parentId)->toBe(5)
        ->and($channel->temporary)->toBeTrue()
        ->and($channel->occupants)->toBe(3);

    Http::assertSent(fn ($request) => $request['parent'] === 5
        && $request['temporary'] === true);
});

it('defaults occupants to zero when absent from the response', function () {
    Http::fake([
        'ts-admin.test/channels' => Http::response([
            'id' => 14,
            'name' => 'no-occupants-field',
            'parent' => 0,
            'temporary' => false,
        ], 201),
    ]);

    $channel = tsClient()->createChannel('no-occupants-field');

    expect($channel->occupants)->toBe(0);
});

it('throws when creating a channel fails', function () {
    Http::fake([
        'ts-admin.test/channels' => Http::response(['detail' => 'unauthorized'], 401),
    ]);

    tsClient()->createChannel('match-1');
})->throws(RequestException::class);

it('reports its provider as teamspeak', function () {
    expect(tsClient()->provider())->toBe(VoiceProvider::TeamSpeak);
});

it('renames a channel via PATCH', function () {
    Http::fake([
        'ts-admin.test/channels/12' => Http::response([
            'id' => 12,
            'name' => 'renamed',
            'parent' => 0,
            'temporary' => false,
            'occupants' => 0,
        ], 200),
    ]);

    tsClient()->renameChannel(12, 'renamed');

    Http::assertSent(fn ($request) => $request->url() === 'http://ts-admin.test/channels/12'
        && $request->method() === 'PATCH'
        && $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request['name'] === 'renamed');
});

it('throws when renaming a channel fails', function () {
    Http::fake([
        'ts-admin.test/channels/12' => Http::response(['detail' => 'unknown channel id'], 404),
    ]);

    tsClient()->renameChannel(12, 'renamed');
})->throws(RequestException::class);

it('deletes a channel via DELETE', function () {
    Http::fake([
        'ts-admin.test/channels/12' => Http::response(null, 204),
    ]);

    tsClient()->deleteChannel(12);

    Http::assertSent(fn ($request) => $request->url() === 'http://ts-admin.test/channels/12'
        && $request->method() === 'DELETE'
        && $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('throws when deleting a channel fails', function () {
    Http::fake([
        'ts-admin.test/channels/12' => Http::response(['detail' => 'unknown channel id'], 404),
    ]);

    tsClient()->deleteChannel(12);
})->throws(RequestException::class);

it('does not retry a 404', function () {
    Http::fake(['ts-admin.test/channels/999' => Http::response('', 404)]);

    tsClient()->deleteChannel(999);
})->throws(RequestException::class);

it('lists channels and maps each to a VoiceChannel', function () {
    Http::fake([
        'ts-admin.test/channels' => Http::response([
            ['id' => 0, 'name' => 'Root', 'parent' => 0, 'temporary' => false, 'occupants' => 5],
            ['id' => 12, 'name' => 'match-1', 'parent' => 0, 'temporary' => false, 'occupants' => 2],
        ], 200),
    ]);

    $channels = tsClient()->listChannels();

    expect($channels)->toHaveCount(2)
        ->and($channels[1]->id)->toBe(12)
        ->and($channels[1]->name)->toBe('match-1')
        ->and($channels[1]->occupants)->toBe(2);

    Http::assertSent(fn ($request) => $request->url() === 'http://ts-admin.test/channels'
        && $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('throws when listing channels fails', function () {
    Http::fake([
        'ts-admin.test/channels' => Http::response(['detail' => 'bad gateway'], 502),
    ]);

    tsClient()->listChannels();
})->throws(RequestException::class);

it('retries transient 503 then succeeds', function () {
    Http::fakeSequence('ts-admin.test/channels')
        ->push('', 503)
        ->push(['id' => 1, 'name' => 'x', 'parent' => 0, 'temporary' => true, 'occupants' => 0], 201);

    expect(tsClient()->createChannel('x', null, true)->id)->toBe(1);

    Http::assertSentCount(2);
});

it('retries transient 429 then succeeds', function () {
    Http::fakeSequence('ts-admin.test/channels')
        ->push('', 429)
        ->push(['id' => 2, 'name' => 'y', 'parent' => 0, 'temporary' => false, 'occupants' => 0], 201);

    expect(tsClient()->createChannel('y')->id)->toBe(2);

    Http::assertSentCount(2);
});

it('retries a connection exception then succeeds', function () {
    Http::fakeSequence('ts-admin.test/channels')
        ->pushFailedConnection()
        ->push(['id' => 3, 'name' => 'z', 'parent' => 0, 'temporary' => false, 'occupants' => 0], 201);

    expect(tsClient()->createChannel('z')->id)->toBe(3);

    Http::assertSentCount(2);
});

it('does not retry a 404 client error and throws immediately', function () {
    Http::fake([
        'ts-admin.test/channels/12' => Http::response(['detail' => 'unknown channel id'], 404),
    ]);

    expect(fn () => tsClient()->deleteChannel(12))
        ->toThrow(RequestException::class);

    Http::assertSentCount(1);
});
