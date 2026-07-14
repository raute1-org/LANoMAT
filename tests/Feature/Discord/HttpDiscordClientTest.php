<?php

use App\Modules\Discord\HttpDiscordClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

it('posts a message with the bot token', function () {
    Http::fake(['discord.com/*' => Http::response(['id' => '99'], 200)]);

    (new HttpDiscordClient('test-token'))->sendMessage('42', 'hi');

    Http::assertSent(fn ($request) => $request->url() === 'https://discord.com/api/v10/channels/42/messages'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bot test-token')
        && $request['content'] === 'hi'
        && ! array_key_exists('embeds', $request->data()));
});

it('posts a message with embeds when provided', function () {
    Http::fake(['discord.com/*' => Http::response(['id' => '99'], 200)]);

    $embeds = [['title' => 'Match result', 'description' => '1:0']];

    (new HttpDiscordClient('test-token'))->sendMessage('42', 'hi', $embeds);

    Http::assertSent(fn ($request) => $request['embeds'] === $embeds);
});

it('throws when sending a message fails', function () {
    Http::fake(['discord.com/*' => Http::response(['message' => 'Unauthorized'], 401)]);

    (new HttpDiscordClient('bad-token'))->sendMessage('42', 'hi');
})->throws(RequestException::class);

it('creates a channel and returns its id', function () {
    Http::fake(['discord.com/*' => Http::response(['id' => 'chan-1'], 201)]);

    $id = (new HttpDiscordClient('test-token'))->createChannel('guild1', 'match-1', 'parent1');

    expect($id)->toBe('chan-1');

    Http::assertSent(fn ($request) => $request->url() === 'https://discord.com/api/v10/guilds/guild1/channels'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bot test-token')
        && $request['name'] === 'match-1'
        && $request['type'] === 0
        && $request['parent_id'] === 'parent1');
});

it('creates a channel without a parent', function () {
    Http::fake(['discord.com/*' => Http::response(['id' => 'chan-1'], 201)]);

    (new HttpDiscordClient('test-token'))->createChannel('guild1', 'match-1');

    Http::assertSent(fn ($request) => ! array_key_exists('parent_id', $request->data()));
});

it('throws when creating a channel fails', function () {
    Http::fake(['discord.com/*' => Http::response(['message' => 'Forbidden'], 403)]);

    (new HttpDiscordClient('bad-token'))->createChannel('guild1', 'match-1');
})->throws(RequestException::class);

it('deletes a channel', function () {
    Http::fake(['discord.com/*' => Http::response([], 200)]);

    (new HttpDiscordClient('test-token'))->deleteChannel('chan-1');

    Http::assertSent(fn ($request) => $request->url() === 'https://discord.com/api/v10/channels/chan-1'
        && $request->method() === 'DELETE'
        && $request->hasHeader('Authorization', 'Bot test-token'));
});

it('throws when deleting a channel fails', function () {
    Http::fake(['discord.com/*' => Http::response(['message' => 'Not Found'], 404)]);

    (new HttpDiscordClient('bad-token'))->deleteChannel('chan-1');
})->throws(RequestException::class);

it('opens a dm channel then posts', function () {
    Http::fake(['discord.com/*' => Http::response(['id' => 'dm1'], 200)]);

    (new HttpDiscordClient('t'))->sendDm('user1', 'ping');

    Http::assertSent(fn ($r) => $r->url() === 'https://discord.com/api/v10/users/@me/channels'
        && $r->method() === 'POST'
        && $r['recipient_id'] === 'user1');
    Http::assertSent(fn ($r) => $r->url() === 'https://discord.com/api/v10/channels/dm1/messages'
        && $r['content'] === 'ping');
});

it('throws when opening a dm channel fails', function () {
    Http::fake(['discord.com/*' => Http::response(['message' => 'Cannot send messages to this user'], 403)]);

    (new HttpDiscordClient('t'))->sendDm('user1', 'ping');
})->throws(RequestException::class);

it('upserts permission overwrites for each entry', function () {
    Http::fake(['discord.com/*' => Http::response([], 204)]);

    $overwrites = [
        ['id' => 'role1', 'type' => 0, 'allow' => '1024', 'deny' => '0'],
        ['id' => 'user1', 'type' => 1, 'allow' => '0', 'deny' => '1024'],
    ];

    (new HttpDiscordClient('test-token'))->upsertPermissionOverwrites('chan-1', $overwrites);

    Http::assertSent(fn ($r) => $r->url() === 'https://discord.com/api/v10/channels/chan-1/permissions/role1'
        && $r->method() === 'PUT'
        && $r['allow'] === '1024');
    Http::assertSent(fn ($r) => $r->url() === 'https://discord.com/api/v10/channels/chan-1/permissions/user1'
        && $r->method() === 'PUT'
        && $r['deny'] === '1024');

    Http::assertSentCount(2);
});

it('throws when a permission overwrite fails', function () {
    Http::fake(['discord.com/*' => Http::response(['message' => 'Missing Permissions'], 403)]);

    (new HttpDiscordClient('test-token'))->upsertPermissionOverwrites('chan-1', [
        ['id' => 'role1', 'type' => 0, 'allow' => '0', 'deny' => '0'],
    ]);
})->throws(RequestException::class);
