<?php

declare(strict_types=1);

namespace App\Modules\Presence\Domain;

/**
 * Where one person is right now: any number of voice rooms (one per provider at most) and at
 * most one game. Pure value object; the snapshot stores it as an array.
 */
final readonly class PersonLocation
{
    /**
     * @param  list<VoiceLocation>  $voice
     */
    public function __construct(
        public int $userId,
        public array $voice = [],
        public ?GameLocation $game = null,
    ) {}

    public function isAnywhere(): bool
    {
        return $this->voice !== [] || $this->game !== null;
    }

    /**
     * @return array{userId: int, voice: list<array<string, mixed>>, game: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'userId' => $this->userId,
            'voice' => array_map(fn (VoiceLocation $v): array => $v->toArray(), $this->voice),
            'game' => $this->game?->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array<string, mixed>> $voice */
        $voice = is_array($data['voice'] ?? null) ? $data['voice'] : [];
        /** @var array<string, mixed>|null $game */
        $game = is_array($data['game'] ?? null) ? $data['game'] : null;

        return new self(
            userId: (int) ($data['userId'] ?? 0),
            voice: array_map(VoiceLocation::fromArray(...), $voice),
            game: $game === null ? null : GameLocation::fromArray($game),
        );
    }
}
