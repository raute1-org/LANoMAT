<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Domain;

/**
 * Someone currently connected to a LiveKit room. `identity` is the server-derived
 * `u<user id>` that was put into their join token, so it maps back to exactly one user.
 */
final readonly class RoomParticipant
{
    public function __construct(
        public string $identity,
        public string $name,
        public bool $canPublish = true,
    ) {}

    public function userId(): ?int
    {
        return preg_match('/^u(\d+)$/', $this->identity, $m) === 1 ? (int) $m[1] : null;
    }
}
