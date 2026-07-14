<?php

declare(strict_types=1);

namespace App\Modules\Voice\Contracts;

use App\Modules\Voice\Domain\MumbleChannel;

interface MumbleClient
{
    public function createChannel(string $name, ?int $parentId = null, bool $temporary = false): MumbleChannel;

    public function renameChannel(int $channelId, string $name): void;

    public function deleteChannel(int $channelId): void;

    /**
     * @return array<int, MumbleChannel>
     */
    public function listChannels(): array;
}
