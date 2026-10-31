<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Contracts;

use App\Modules\WebVoice\Domain\RoomParticipant;
use App\Modules\WebVoice\Domain\WebVoiceUnavailable;
use App\Modules\WebVoice\Testing\FakeWebVoiceClient;

/**
 * The only door to the WebRTC SFU (self-hosted LiveKit). Tests bind
 * {@see FakeWebVoiceClient}; nothing in the suite talks to a real server.
 */
interface WebVoiceClient
{
    /**
     * A signed join token for exactly one room. Pure local signing, no network call.
     *
     * @throws WebVoiceUnavailable when the API key or secret is not configured
     */
    public function issueToken(string $room, string $identity, string $name, bool $canPublish): string;

    /**
     * Who is connected to the room right now. A room that does not exist yet is empty.
     *
     * @return list<RoomParticipant>
     *
     * @throws WebVoiceUnavailable
     */
    public function participants(string $room): array;

    /** @throws WebVoiceUnavailable */
    public function removeParticipant(string $room, string $identity): void;

    /**
     * Grants or revokes the right to publish audio for a participant who is connected now.
     *
     * @throws WebVoiceUnavailable
     */
    public function setCanPublish(string $room, string $identity, bool $canPublish): void;
}
