<?php

declare(strict_types=1);

namespace App\Modules\Presence\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;

/**
 * How many of an event's participants are in which game right now, as one viewer may see them
 * (same privacy rules as {@see PeopleDirectory}, via {@see PresenceVisibility}). Cache-only.
 */
final class LocatedGames
{
    public function __construct(private readonly PresenceVisibility $visibility) {}

    /**
     * @return array<string, int> game display name => number of visible participants in it
     */
    public function countsFor(Event $event, User $viewer): array
    {
        $snapshot = $this->visibility->snapshot();

        if ($snapshot === null) {
            return [];
        }

        $counts = [];

        foreach ($this->visibility->visibleParticipants($event, $viewer) as $user) {
            $name = $snapshot->people[$user->id]->game->game ?? null;

            if ($name === null || $name === '') {
                continue;
            }

            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        return $counts;
    }
}
