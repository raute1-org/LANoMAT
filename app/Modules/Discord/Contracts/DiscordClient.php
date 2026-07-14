<?php

namespace App\Modules\Discord\Contracts;

interface DiscordClient
{
    /**
     * @param  array<int, array<string, mixed>>  $embeds
     */
    public function sendMessage(string $channelId, string $content, array $embeds = []): void;

    public function createChannel(string $guildId, string $name, ?string $parentId = null): string;

    public function deleteChannel(string $channelId): void;

    public function sendDm(string $userDiscordId, string $content): void;

    /**
     * @param  array<int, array<string, mixed>>  $overwrites
     */
    public function upsertPermissionOverwrites(string $channelId, array $overwrites): void;
}
