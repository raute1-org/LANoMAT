<?php

declare(strict_types=1);

namespace App\Modules\Voice\Testing;

use App\Modules\Voice\Contracts\MumbleClient;
use App\Modules\Voice\Domain\MumbleChannel;
use PHPUnit\Framework\Assert;

class FakeMumbleClient implements MumbleClient
{
    /** @var array<int, MumbleChannel> */
    public array $channels = [];

    /** @var array<int, int> */
    public array $deletedChannelIds = [];

    private int $sequence = 0;

    public function createChannel(string $name, ?int $parentId = null, bool $temporary = false): MumbleChannel
    {
        $channel = new MumbleChannel(++$this->sequence, $name, $parentId, $temporary);
        $this->channels[$channel->id] = $channel;

        return $channel;
    }

    public function renameChannel(int $channelId, string $name): void
    {
        $existing = $this->channels[$channelId] ?? null;

        if ($existing === null) {
            return;
        }

        $this->channels[$channelId] = new MumbleChannel(
            $existing->id,
            $name,
            $existing->parentId,
            $existing->temporary,
        );
    }

    public function deleteChannel(int $channelId): void
    {
        $this->deletedChannelIds[] = $channelId;
        unset($this->channels[$channelId]);
    }

    public function listChannels(): array
    {
        return array_values($this->channels);
    }

    public function assertChannelCreated(string $name, ?int $parentId = null): void
    {
        $match = collect($this->channels)->contains(
            fn (MumbleChannel $c) => $c->name === $name && ($parentId === null || $c->parentId === $parentId)
        );
        Assert::assertTrue($match, "No matching channel created with name {$name}.");
    }

    public function assertChannelDeleted(int $channelId): void
    {
        Assert::assertContains($channelId, $this->deletedChannelIds, "Channel {$channelId} was not deleted.");
    }
}
