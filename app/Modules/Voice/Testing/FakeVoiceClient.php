<?php

declare(strict_types=1);

namespace App\Modules\Voice\Testing;

use App\Modules\Voice\Contracts\VoiceClient;
use App\Modules\Voice\Domain\VoiceChannel;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\Support\VoiceOccupancy;
use PHPUnit\Framework\Assert;

class FakeVoiceClient implements VoiceClient
{
    /** @var array<int, VoiceChannel> */
    public array $channels = [];

    /** @var array<int, int> */
    public array $deletedChannelIds = [];

    /**
     * Test hook: when set and it returns true for a channel name, creating that channel
     * fails, to exercise what the provisioning jobs do when a provider breaks half way.
     *
     * @var (\Closure(string): bool)|null
     */
    public ?\Closure $failCreateWhen = null;

    private int $sequence = 0;

    public function __construct(private readonly VoiceProvider $provider = VoiceProvider::Mumble) {}

    public function provider(): VoiceProvider
    {
        return $this->provider;
    }

    public function createChannel(string $name, ?int $parentId = null, bool $temporary = false): VoiceChannel
    {
        if ($this->failCreateWhen !== null && ($this->failCreateWhen)($name)) {
            throw new \RuntimeException("Creating voice channel '{$name}' failed.");
        }

        $channel = new VoiceChannel(++$this->sequence, $name, $parentId, $temporary);
        $this->channels[$channel->id] = $channel;

        return $channel;
    }

    public function renameChannel(int $channelId, string $name): void
    {
        $existing = $this->channels[$channelId] ?? null;

        if ($existing === null) {
            return;
        }

        $this->channels[$channelId] = new VoiceChannel(
            $existing->id,
            $name,
            $existing->parentId,
            $existing->temporary,
            $existing->occupants,
            $existing->occupantNames,
        );
    }

    /**
     * Test hook (occupancy is read-only in the real contract — no provider
     * exposes a way to *set* occupants, only to report them): seeds a
     * channel's live occupant count, e.g. to assert
     * {@see VoiceOccupancy} aggregates it
     * correctly. VoiceChannel is immutable, so the stored instance is
     * rebuilt with the new count, mirroring renameChannel above.
     */
    public function setOccupants(int $channelId, int $n): void
    {
        $existing = $this->channels[$channelId] ?? null;

        if ($existing === null) {
            return;
        }

        $this->channels[$channelId] = new VoiceChannel(
            $existing->id,
            $existing->name,
            $existing->parentId,
            $existing->temporary,
            $n,
            $existing->occupantNames,
        );
    }

    /**
     * Test hook: who is in a channel, by the display name the voice server shows. Also sets the
     * head count, as a real sidecar would report both.
     *
     * @param  list<string>  $names
     */
    public function setOccupantNames(int $channelId, array $names): void
    {
        $existing = $this->channels[$channelId] ?? null;

        if ($existing === null) {
            return;
        }

        $this->channels[$channelId] = new VoiceChannel(
            $existing->id,
            $existing->name,
            $existing->parentId,
            $existing->temporary,
            count($names),
            $names,
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
            fn (VoiceChannel $c) => $c->name === $name && ($parentId === null || $c->parentId === $parentId)
        );
        Assert::assertTrue($match, "No matching channel created with name {$name}.");
    }

    public function assertChannelDeleted(int $channelId): void
    {
        Assert::assertContains($channelId, $this->deletedChannelIds, "Channel {$channelId} was not deleted.");
    }
}
