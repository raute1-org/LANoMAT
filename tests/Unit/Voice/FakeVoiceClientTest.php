<?php

use App\Modules\Voice\Contracts\VoiceClient;
use App\Modules\Voice\Domain\VoiceChannel;
use App\Modules\Voice\Testing\FakeVoiceClient;
use PHPUnit\Framework\ExpectationFailedException;

it('creates, lists and deletes channels', function () {
    $fake = new FakeVoiceClient;

    $channel = $fake->createChannel('match-1');

    expect($channel)->toBeInstanceOf(VoiceChannel::class)
        ->and($channel->name)->toBe('match-1')
        ->and($channel->parentId)->toBeNull()
        ->and($channel->temporary)->toBeFalse();

    expect($fake->listChannels())->toHaveCount(1)
        ->and($fake->listChannels()[0]->id)->toBe($channel->id);

    $fake->deleteChannel($channel->id);

    expect($fake->listChannels())->toHaveCount(0);
});

it('creates a channel with a parent and temporary flag', function () {
    $fake = new FakeVoiceClient;

    $channel = $fake->createChannel('sub-room', 42, true);

    expect($channel->parentId)->toBe(42)
        ->and($channel->temporary)->toBeTrue();
});

it('renames a channel', function () {
    $fake = new FakeVoiceClient;
    $channel = $fake->createChannel('old-name');

    $fake->renameChannel($channel->id, 'new-name');

    expect($fake->listChannels()[0]->name)->toBe('new-name');
});

it('assigns increasing unique ids across channels', function () {
    $fake = new FakeVoiceClient;

    $first = $fake->createChannel('a');
    $second = $fake->createChannel('b');

    expect($second->id)->not->toBe($first->id);
});

it('asserts a channel was created', function () {
    $fake = new FakeVoiceClient;
    $fake->createChannel('match-1');

    $fake->assertChannelCreated('match-1');
});

it('fails assertChannelCreated when no matching channel exists', function () {
    $fake = new FakeVoiceClient;
    $fake->createChannel('match-1');

    $fake->assertChannelCreated('match-2');
})->throws(ExpectationFailedException::class);

it('asserts a channel was deleted', function () {
    $fake = new FakeVoiceClient;
    $channel = $fake->createChannel('match-1');
    $fake->deleteChannel($channel->id);

    $fake->assertChannelDeleted($channel->id);
});

it('fails assertChannelDeleted when the channel was never deleted', function () {
    $fake = new FakeVoiceClient;
    $channel = $fake->createChannel('match-1');

    $fake->assertChannelDeleted($channel->id);
})->throws(ExpectationFailedException::class);

it('fakeMumble helper swaps the VoiceClient binding', function () {
    $fake = fakeMumble();

    $client = app(VoiceClient::class);

    expect($client)->toBe($fake)
        ->and($client)->toBeInstanceOf(FakeVoiceClient::class);
});
