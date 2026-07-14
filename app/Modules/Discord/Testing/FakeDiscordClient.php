<?php

namespace App\Modules\Discord\Testing;

use App\Modules\Discord\Contracts\DiscordClient;
use PHPUnit\Framework\Assert;

class FakeDiscordClient implements DiscordClient
{
    /** @var array<int, array{channelId: string, content: string, embeds: array<int, mixed>}> */
    public array $messages = [];

    /** @var array<int, array{userDiscordId: string, content: string}> */
    public array $dms = [];

    /** @var array<int, array{guildId: string, name: string, parentId: ?string, id: string}> */
    public array $channels = [];

    /** @var array<int, array{channelId: string, overwrites: array<int, mixed>}> */
    public array $overwrites = [];

    /** @var array<int, string> */
    public array $deletedChannelIds = [];

    private int $sequence = 0;

    public function sendMessage(string $channelId, string $content, array $embeds = []): void
    {
        $this->messages[] = compact('channelId', 'content', 'embeds');
    }

    public function createChannel(string $guildId, string $name, ?string $parentId = null): string
    {
        $id = 'fake-channel-'.(++$this->sequence);
        $this->channels[] = compact('guildId', 'name', 'parentId', 'id');

        return $id;
    }

    public function deleteChannel(string $channelId): void
    {
        $this->deletedChannelIds[] = $channelId;
        $this->channels = array_values(array_filter($this->channels, fn ($c) => $c['id'] !== $channelId));
    }

    public function sendDm(string $userDiscordId, string $content): void
    {
        $this->dms[] = compact('userDiscordId', 'content');
    }

    public function upsertPermissionOverwrites(string $channelId, array $overwrites): void
    {
        $this->overwrites[] = compact('channelId', 'overwrites');
    }

    public function assertMessageSent(string $channelId, ?string $contains = null): void
    {
        $match = collect($this->messages)->contains(
            fn ($m) => $m['channelId'] === $channelId && ($contains === null || str_contains($m['content'], $contains))
        );
        Assert::assertTrue($match, "No matching message sent to channel {$channelId}.");
    }

    public function assertDmSent(string $userDiscordId): void
    {
        Assert::assertTrue(
            collect($this->dms)->contains(fn ($d) => $d['userDiscordId'] === $userDiscordId),
            "No DM sent to user {$userDiscordId}."
        );
    }

    public function assertChannelCreated(string $guildId, ?string $name = null): void
    {
        Assert::assertTrue(
            collect($this->channels)->contains(
                fn ($c) => $c['guildId'] === $guildId && ($name === null || $c['name'] === $name)
            ),
            "No matching channel created in guild {$guildId}."
        );
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->messages, 'Expected no Discord messages.');
        Assert::assertSame([], $this->dms, 'Expected no Discord DMs.');
    }

    public function assertChannelDeleted(string $channelId): void
    {
        Assert::assertContains($channelId, $this->deletedChannelIds, "Channel {$channelId} was not deleted.");
    }

    public function assertPermissionOverwritten(string $channelId, string $discordUserId): void
    {
        $match = collect($this->overwrites)
            ->where('channelId', $channelId)
            ->contains(fn ($o) => collect($o['overwrites'])->contains(fn ($ow) => ($ow['id'] ?? null) === $discordUserId));

        Assert::assertTrue($match, "No permission overwrite for user {$discordUserId} on channel {$channelId}.");
    }
}
