<?php

declare(strict_types=1);

namespace App\Modules\Games\Domain;

/**
 * "So schaust du zu" — how a spectator watches a game's matches: a GOTV/
 * observer connect string, a free-text note on requesting an observer slot,
 * and/or a free-text note on demo/replay availability. Mirrors InstallHint's
 * typed-jsonb value-object shape (see roadmap insight #9 / ServerConfigCast):
 * never a loose string=>string map, so it always decodes to a well-defined
 * shape rather than an arbitrary array.
 */
final readonly class SpectateHint
{
    public function __construct(
        public ?string $gotvConnect = null,
        public ?string $observerNote = null,
        public ?string $replayNote = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $data = [
            'gotv_connect' => $this->gotvConnect,
            'observer_note' => $this->observerNote,
            'replay_note' => $this->replayNote,
        ];

        return array_filter(
            $data,
            static fn (?string $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            gotvConnect: $data['gotv_connect'] ?? null,
            observerNote: $data['observer_note'] ?? null,
            replayNote: $data['replay_note'] ?? null,
        );
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }
}
