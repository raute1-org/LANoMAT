<?php

declare(strict_types=1);

namespace App\Modules\Presence\Domain;

/**
 * "Someone is in this voice room right now", as a voice source reports it. Either the source
 * already knows the LANoMAT user exactly (`userId`: a Discord account mapped by id, a LiveKit
 * identity `u<id>`), or it only has the name the voice server shows (`name`), which the resolver
 * may turn into a user only when exactly one person can have that name.
 *
 * `audience` is who may follow someone into the room: null when it is open to everybody, else
 * the user ids on the roster (orga may always join, that rule lives with the reader).
 */
final readonly class VoiceSighting
{
    public const string KIND_MATCH = 'match';

    public const string KIND_TEAM = 'team';

    public const string KIND_GENERAL = 'general';

    public const string KIND_OTHER = 'other';

    /**
     * @param  list<int>|null  $audience
     */
    public function __construct(
        public string $provider,
        public string $roomRef,
        public string $roomLabel,
        public string $kind,
        public ?int $userId = null,
        public ?string $name = null,
        public ?array $audience = null,
        public ?string $joinPath = null,
    ) {}
}
