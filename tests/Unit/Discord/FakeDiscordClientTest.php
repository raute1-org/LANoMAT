<?php

use App\Modules\Discord\Testing\FakeDiscordClient;
use PHPUnit\Framework\ExpectationFailedException;

it('records messages and channels', function () {
    $fake = new FakeDiscordClient;

    $id = $fake->createChannel('guild1', 'match-1');
    $fake->sendMessage($id, 'Hello LAN');

    $fake->assertMessageSent($id, 'Hello');
    expect($fake->channels)->toHaveCount(1);

    $fake->deleteChannel($id);
    expect($fake->channels)->toHaveCount(0);
});

it('records dms', function () {
    $fake = new FakeDiscordClient;
    $fake->sendDm('123', 'ping');

    $fake->assertDmSent('123');
});

it('records permission overwrites', function () {
    $fake = new FakeDiscordClient;

    $fake->upsertPermissionOverwrites('42', [
        ['id' => 'role1', 'type' => 0, 'allow' => '1024', 'deny' => '0'],
    ]);

    expect($fake->overwrites)->toHaveCount(1)
        ->and($fake->overwrites[0]['channelId'])->toBe('42');
});

it('fails assertMessageSent when no message was sent', function () {
    $fake = new FakeDiscordClient;

    $fake->assertMessageSent('42');
})->throws(ExpectationFailedException::class);

it('fails assertMessageSent when content does not match', function () {
    $fake = new FakeDiscordClient;
    $fake->sendMessage('42', 'Hello LAN');

    $fake->assertMessageSent('42', 'Goodbye');
})->throws(ExpectationFailedException::class);

it('fails assertDmSent when no dm was sent', function () {
    $fake = new FakeDiscordClient;

    $fake->assertDmSent('123');
})->throws(ExpectationFailedException::class);

it('asserts nothing sent', function () {
    $fake = new FakeDiscordClient;

    $fake->assertNothingSent();
});

it('fails assertNothingSent when a message was sent', function () {
    $fake = new FakeDiscordClient;
    $fake->sendMessage('42', 'hi');

    $fake->assertNothingSent();
})->throws(ExpectationFailedException::class);

it('asserts a channel was created', function () {
    $fake = new FakeDiscordClient;
    $fake->createChannel('guild1', 'match-1');

    $fake->assertChannelCreated('guild1', 'match-1');
});

it('fails assertChannelCreated when no matching channel exists', function () {
    $fake = new FakeDiscordClient;
    $fake->createChannel('guild1', 'match-1');

    $fake->assertChannelCreated('guild1', 'match-2');
})->throws(ExpectationFailedException::class);
