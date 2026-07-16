<?php

namespace App\Modules\Schedule\Contracts;

use App\Models\User;
use App\Modules\Schedule\Models\ScheduleItem;
use Illuminate\Support\Collection;

/**
 * Resolves the "affected participants" of a schedule item — users who are
 * enrolled in whatever the item is derived from (today: a tournament's
 * entries), as opposed to users who merely favorited it. Kept as a
 * consumer-side contract in `Schedule` so the module can alarm participants
 * on a `starts_at` change (see `AlarmScheduleItemChanged`) without querying
 * another module's tables directly — `Tournaments` binds the implementation
 * (see `TournamentScheduleParticipantResolver`).
 *
 * Returns an empty collection for schedule items with no known participant
 * source (e.g. a custom item, or a `ref_type` no implementation recognizes).
 */
interface ScheduleParticipantResolver
{
    /**
     * @return Collection<int, User>
     */
    public function usersFor(ScheduleItem $item): Collection;
}
