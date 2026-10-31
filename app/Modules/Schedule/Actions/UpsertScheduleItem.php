<?php

namespace App\Modules\Schedule\Actions;

use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Models\ScheduleItem;
use Illuminate\Validation\ValidationException;

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

        if ($item->ends_at !== null && isset($item->starts_at) && $item->ends_at->lt($item->starts_at)) {
            throw ValidationException::withMessages([
                'ends_at' => __('schedule.validation.ends_after_starts'),
            ]);
        }

        $item->event()->associate($event);
        $item->save();

        return $item;
    }
}
