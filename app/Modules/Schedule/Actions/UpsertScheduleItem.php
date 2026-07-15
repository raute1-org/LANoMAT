<?php

namespace App\Modules\Schedule\Actions;

use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Models\ScheduleItem;

class UpsertScheduleItem
{
    /**
     * Create a new schedule item for the given event, or update `$item` in
     * place when one is passed. Attributes are expected to already be
     * validated by the caller (Filament form / controller request).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Event $event, array $attributes, ?ScheduleItem $item = null): ScheduleItem
    {
        $item ??= new ScheduleItem;

        $item->fill($attributes);
        $item->event()->associate($event);
        $item->save();

        return $item;
    }
}
