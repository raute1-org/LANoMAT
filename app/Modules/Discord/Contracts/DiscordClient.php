<?php

namespace App\Modules\Discord\Contracts;

interface DiscordClient
{
    /**
     * @param  array<int, array<string, mixed>>  $embeds
     */
    public function sendMessage(string $channelId, string $content, array $embeds = []): void;

    /**
     * Creates a text channel, or a voice channel when `$voice` is true.
     */
    public function createChannel(string $guildId, string $name, ?string $parentId = null, bool $voice = false): string;

    public function deleteChannel(string $channelId): void;

    /**
     * Creates a channel category and returns its id.
     */
    public function createCategory(string $guildId, string $name): string;

    /**
     * Moves a channel into a category, or out of any category when `$parentId` is null.
     */
    public function moveChannel(string $channelId, ?string $parentId): void;

    /**
     * Every channel of the guild, categories included (type 4), each with the id of its category.
     *
     * @return array<int, array{id: string, name: string, type: int, parentId: ?string}>
     */
    public function listChannels(string $guildId): array;

    public function sendDm(string $userDiscordId, string $content): void;

    /**
     * @param  array<int, array<string, mixed>>  $overwrites
     */
    public function upsertPermissionOverwrites(string $channelId, array $overwrites): void;
}
