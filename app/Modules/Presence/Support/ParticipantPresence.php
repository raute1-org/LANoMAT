<?php

declare(strict_types=1);

namespace App\Modules\Presence\Support;

/**
 * One checked-in participant's presence-board entry: where they're seated
 * (if anywhere) and what they're currently doing (idle vs. live in a match).
 *
 * `activity` is `null` when idle; otherwise `"{game} · {matchLabel}"`
 * (see {@see PresenceProjection} for how it's derived).
 */
final readonly class ParticipantPresence
{
    public function __construct(
        public string $name,
        public ?string $avatarUrl,
        public ?string $seatLabel,
        public ?string $activity,
        public bool $isPlaying,
    ) {}

    /**
     * @return array{name: string, avatarUrl: ?string, seatLabel: ?string, activity: ?string, isPlaying: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'avatarUrl' => $this->avatarUrl,
            'seatLabel' => $this->seatLabel,
            'activity' => $this->activity,
            'isPlaying' => $this->isPlaying,
        ];
    }
}
