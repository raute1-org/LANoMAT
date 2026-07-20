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
 * `userId` is the underlying `User` id. It exists so a viewer-aware layer
 * (the authorized `PresencePageController`, never this viewer-agnostic
 * projection) can match participants against the viewer's friend list. It
 * is present in `toArray()` but MUST NOT be treated as sensitive on its
 * own — the friend *relationship* (`isFriend`) is the private fact, and
 * that is added only in the controller's Inertia payload, never here and
 * never in `PresenceUpdated::broadcastWith()`.
 *
 * `activity` is `null` when idle; otherwise `"{game} · {matchLabel}"`
 * (see {@see PresenceProjection} for how it's derived).
 */
final readonly class ParticipantPresence
{
    public function __construct(
        public int $registrationId,
        public int $userId,
        public string $name,
        public ?string $avatarUrl,
        public ?string $streamUrl,
        public ?string $seatLabel,
        public ?string $activity,
        public bool $isPlaying,
    ) {}

    /**
     * @return array{registrationId: int, userId: int, name: string, avatarUrl: ?string, streamUrl: ?string, seatLabel: ?string, activity: ?string, isPlaying: bool}
     */
    public function toArray(): array
    {
        return [
            'registrationId' => $this->registrationId,
            'userId' => $this->userId,
            'name' => $this->name,
            'avatarUrl' => $this->avatarUrl,
            'streamUrl' => $this->streamUrl,
            'seatLabel' => $this->seatLabel,
            'activity' => $this->activity,
            'isPlaying' => $this->isPlaying,
        ];
    }
}
