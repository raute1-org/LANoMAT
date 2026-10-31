<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Testing;

use App\Modules\WebVoice\Contracts\WebVoiceClient;
use App\Modules\WebVoice\Domain\RoomParticipant;
use App\Modules\WebVoice\Domain\WebVoiceUnavailable;

/**
 * In-memory {@see WebVoiceClient}: records what was asked of it and serves a scripted occupancy.
 */
class FakeWebVoiceClient implements WebVoiceClient
{
    /** @var list<array{room: string, identity: string, name: string, canPublish: bool}> */
    public array $issued = [];

    /** @var array<string, list<RoomParticipant>> */
    public array $rooms = [];

    /** @var list<array{room: string, identity: string}> */
    public array $removed = [];

    /** @var list<array{room: string, identity: string, canPublish: bool}> */
    public array $publishChanges = [];

    public bool $unavailable = false;

    public function issueToken(string $room, string $identity, string $name, bool $canPublish): string
    {
        $this->issued[] = compact('room', 'identity', 'name', 'canPublish');

        return 'fake-token-'.count($this->issued);
    }

    public function participants(string $room): array
    {
        $this->failIfDown();

        return $this->rooms[$room] ?? [];
    }

    public function removeParticipant(string $room, string $identity): void
    {
        $this->failIfDown();

        $this->removed[] = compact('room', 'identity');
        $this->rooms[$room] = array_values(array_filter(
            $this->rooms[$room] ?? [],
            fn (RoomParticipant $p): bool => $p->identity !== $identity,
        ));
    }

    public function setCanPublish(string $room, string $identity, bool $canPublish): void
    {
        $this->failIfDown();

        $this->publishChanges[] = compact('room', 'identity', 'canPublish');
    }

    /** Puts someone into a room, as if they had connected with a token. */
    public function join(string $room, int $userId, string $name = 'Someone'): void
    {
        $this->rooms[$room][] = new RoomParticipant('u'.$userId, $name);
    }

    private function failIfDown(): void
    {
        if ($this->unavailable) {
            throw new WebVoiceUnavailable('fake: SFU unreachable');
        }
    }
}
