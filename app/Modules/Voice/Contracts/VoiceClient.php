<?php

declare(strict_types=1);

namespace App\Modules\Voice\Contracts;

use App\Modules\Voice\Domain\VoiceChannel;
use App\Modules\Voice\Domain\VoiceProvider;

interface VoiceClient
{
    public function provider(): VoiceProvider;

    public function createChannel(string $name, ?int $parentId = null, bool $temporary = false): VoiceChannel;

    public function renameChannel(int $channelId, string $name): void;

    public function deleteChannel(int $channelId): void;

    /**
     * @return array<int, VoiceChannel>
     */
    public function listChannels(): array;
}
