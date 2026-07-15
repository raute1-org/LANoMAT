<?php

namespace App\Modules\Schedule\Actions;

use App\Modules\Schedule\Enums\ScheduleItemType;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Tournaments\Models\Tournament;

class SyncTournamentScheduleItem
{
    /**
     * Idempotently upsert the `type=Tournament` schedule item mirroring
     * `$tournament`, keyed by `(ref_type='tournament', ref_id=$tournament->id)`
     * so repeated saves update the same row instead of duplicating it.
     */
    public function handle(Tournament $tournament): ScheduleItem
    {
        $item = ScheduleItem::query()
            ->where('ref_type', 'tournament')
            ->where('ref_id', $tournament->id)
            ->first();

        $item ??= new ScheduleItem([
            'ref_type' => 'tournament',
            'ref_id' => $tournament->id,
        ]);

        $item->event()->associate($tournament->event);
        $item->type = ScheduleItemType::Tournament;
        $item->title = $tournament->name;
        $item->starts_at = $tournament->starts_at;
        $item->save();

        return $item;
    }
}
