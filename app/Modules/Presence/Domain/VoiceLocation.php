<?php

declare(strict_types=1);

namespace App\Modules\Presence\Domain;

/**
 * Where a person is in voice on one provider. A person can have several at once (Discord and a
 * web room, say). `roomRef` is the provider's own room id; `audience` and `joinPath` are kept
 * for the reader's follow and visibility decisions and never leave the API as such.
 */
final readonly class VoiceLocation
{
    /**
     * @param  list<int>|null  $audience
     */
    public function __construct(
        public string $provider,
        public string $roomLabel,
        public string $roomRef,
        public string $kind,
        public ?array $audience,
        public ?string $joinPath,
        public string $since,
    ) {}

    public function key(): string
    {
        return $this->provider.'|'.$this->roomRef;
    }

    /**
     * @return array{provider: string, roomLabel: string, roomRef: string, kind: string, audience: list<int>|null, joinPath: ?string, since: string}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'roomLabel' => $this->roomLabel,
            'roomRef' => $this->roomRef,
            'kind' => $this->kind,
            'audience' => $this->audience,
            'joinPath' => $this->joinPath,
            'since' => $this->since,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $audience = $data['audience'] ?? null;

        return new self(
            provider: (string) ($data['provider'] ?? ''),
            roomLabel: (string) ($data['roomLabel'] ?? ''),
            roomRef: (string) ($data['roomRef'] ?? ''),
            kind: (string) ($data['kind'] ?? VoiceSighting::KIND_OTHER),
            audience: is_array($audience) ? array_values(array_map('intval', $audience)) : null,
            joinPath: isset($data['joinPath']) ? (string) $data['joinPath'] : null,
            since: (string) ($data['since'] ?? ''),
        );
    }
}
