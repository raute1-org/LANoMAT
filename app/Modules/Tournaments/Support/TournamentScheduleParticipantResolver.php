<?php

namespace App\Modules\Tournaments\Support;

use App\Models\User;
use App\Modules\Schedule\Contracts\ScheduleParticipantResolver;
use App\Modules\Schedule\Models\ScheduleItem;
use Illuminate\Support\Collection;

/**
 * The `Tournaments` implementation of `ScheduleParticipantResolver`: for a
 * `ref_type='tournament'` schedule item (see `SyncTournamentScheduleItem`),
 * resolves the tournament's enrolled participants via `EntryRoster`; any
 * other item (a custom, non-tournament-derived schedule item) yields an
 * empty collection, since `Tournaments` has no participants to add for it.
 */
class TournamentScheduleParticipantResolver implements ScheduleParticipantResolver
{
    /**
     * @return Collection<int, User>
     */
    public function usersFor(ScheduleItem $item): Collection
    {
        if ($item->ref_type !== 'tournament' || $item->ref_id === null) {
            return collect();
        }

        return EntryRoster::usersForTournament($item->ref_id);
    }
}
