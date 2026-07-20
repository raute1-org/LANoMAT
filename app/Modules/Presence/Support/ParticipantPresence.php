<?php

declare(strict_types=1);

namespace App\Modules\Presence\Support;

use App\Modules\Registration\Models\EventRegistration;

/**
 * One checked-in participant's presence-board entry: where they're seated
 * (if anywhere) and what they're currently doing (idle vs. live in a match).
 *
 * `registrationId` is the per-event-unique {@see EventRegistration} id — the
 * stable identity for this entry (two attendees can share a `User.name` at
 * a LAN, so the frontend must key its live-reloading participant list on
 * this, not on `name`).
 *
 * `activity` is `null` when idle; otherwise `"{game} · {matchLabel}"`
 * (see {@see PresenceProjection} for how it's derived).
 */
final readonly class ParticipantPresence
{
    public function __construct(
        public int $registrationId,
        public string $name,
        public ?string $avatarUrl,
        public ?string $streamUrl,
        public ?string $seatLabel,
        public ?string $activity,
        public bool $isPlaying,
    ) {}

    /**
     * @return array{registrationId: int, name: string, avatarUrl: ?string, streamUrl: ?string, seatLabel: ?string, activity: ?string, isPlaying: bool}
     */
    public function toArray(): array
    {
        return [
            'registrationId' => $this->registrationId,
            'name' => $this->name,
            'avatarUrl' => $this->avatarUrl,
            'streamUrl' => $this->streamUrl,
            'seatLabel' => $this->seatLabel,
            'activity' => $this->activity,
            'isPlaying' => $this->isPlaying,
        ];
    }
}
