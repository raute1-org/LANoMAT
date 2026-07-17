<?php

declare(strict_types=1);

namespace App\Modules\Voice;

use App\Modules\Voice\Contracts\VoiceClient;
use App\Modules\Voice\Domain\VoiceChannel;
use App\Modules\Voice\Domain\VoiceProvider;
use RuntimeException;

/**
 * Minimal stub so the provider registry (Task 8.2) can resolve a
 * TeamSpeak client instance. Task 8.3 fills in the real bodies against
 * the teamspeak-admin sidecar plus tests.
 */
class HttpTeamSpeakClient implements VoiceClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    public function provider(): VoiceProvider
    {
        return VoiceProvider::TeamSpeak;
    }

    public function createChannel(string $name, ?int $parentId = null, bool $temporary = false): VoiceChannel
    {
        throw $this->notImplemented();
    }

    public function renameChannel(int $channelId, string $name): void
    {
        throw $this->notImplemented();
    }

    public function deleteChannel(int $channelId): void
    {
        throw $this->notImplemented();
    }

    public function listChannels(): array
    {
        throw $this->notImplemented();
    }

    private function notImplemented(): RuntimeException
    {
        return new RuntimeException(
            "HttpTeamSpeakClient not implemented until Task 8.3 (baseUrl: {$this->baseUrl}, token configured: "
            .($this->token !== '' ? 'yes' : 'no').')',
        );
    }
}
