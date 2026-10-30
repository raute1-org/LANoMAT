<?php

namespace App\Modules\Registration\Filament\Widgets\Concerns;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Support\ArrivalOverviewData;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * Shared by the arrival-overview widgets. The event is held as a scalar key and
 * re-queried + authorized on every use (widgets do not re-run page authorization).
 */
trait ForArrivalOverview
{
    #[Locked]
    public int $eventId = 0;

    protected function event(): Event
    {
        $event = Event::query()->findOrFail($this->eventId);
        Gate::authorize('view', $event);

        return $event;
    }

    protected function overview(): ArrivalOverviewData
    {
        return ArrivalOverviewData::for($this->event());
    }
}
