<?php

namespace App\Modules\Schedule\Support;

use App\Modules\Schedule\Contracts\ScheduleParticipantResolver;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Schedule\Models\ScheduleItemFavorite;

/**
 * Everyone affected by a schedule item: its favoriters plus, for items owned
 * by another aggregate (today a tournament), the participants resolved via
 * {@see ScheduleParticipantResolver}. Deduplicated by user id.
 */
class ScheduleAudience
{
    /**
     * @return list<int>
     */
    public static function userIds(ScheduleItem $item): array
    {
        $favoriterIds = ScheduleItemFavorite::query()
            ->where('schedule_item_id', $item->id)
            ->pluck('user_id');

        $participantIds = app(ScheduleParticipantResolver::class)->usersFor($item)->pluck('id');

        return array_values($favoriterIds->concat($participantIds)
            ->unique()
            ->map(fn ($id): int => (int) $id)
            ->all());
    }
}
