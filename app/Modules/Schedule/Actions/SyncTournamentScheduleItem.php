<?php

namespace App\Modules\Schedule\Actions;

use App\Modules\Schedule\Enums\ScheduleItemType;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Support\Facades\DB;

class SyncTournamentScheduleItem
{
    /**
     * Idempotently upsert the `type=Tournament` schedule item mirroring
     * `$tournament`, keyed by `(ref_type='tournament', ref_id=$tournament->id)`
     * so repeated saves update the same row instead of duplicating it.
     *
     * `ref_type`/`ref_id` are ownership markers and therefore not
     * mass-assignable (see `ScheduleItem::$fillable`); they are set here via
     * explicit property assignment only.
     *
     * The read-then-write is wrapped in a transaction that locks the parent
     * `Tournament` row first (same lock-order convention as
     * `StartTournament`/`EnrollSolo`/`RegisterForEvent`), so two concurrent
     * syncs for the same tournament serialize instead of racing to insert
     * duplicate rows — the `(ref_type, ref_id)` index is not unique.
     */
    public function handle(Tournament $tournament): ScheduleItem
    {
        return DB::transaction(function () use ($tournament): ScheduleItem {
            $tournament = Tournament::query()->whereKey($tournament->getKey())->lockForUpdate()->firstOrFail();

            $item = ScheduleItem::query()
                ->where('ref_type', 'tournament')
                ->where('ref_id', $tournament->id)
                ->first();

            $item ??= new ScheduleItem;
            $item->ref_type = 'tournament';
            $item->ref_id = $tournament->id;

            $item->event()->associate($tournament->event);
            $item->type = ScheduleItemType::Tournament;
            $item->title = $tournament->name;
            $item->starts_at = $tournament->starts_at;
            $item->save();

            return $item;
        });
    }
}
