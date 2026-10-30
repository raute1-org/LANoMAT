<?php

namespace App\Modules\Registration\Filament\Widgets;

use App\Modules\Registration\Filament\Widgets\Concerns\ForArrivalOverview;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ArrivalStatsWidget extends StatsOverviewWidget
{
    use ForArrivalOverview;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 5;
    }

    protected function getStats(): array
    {
        $data = $this->overview();
        $f = $data->forecast;

        return [
            Stat::make(__('registration.admin.stats.registered'), $f->registered),
            Stat::make(__('registration.admin.stats.with_plan'), $f->placed)
                ->description(__('registration.admin.stats.with_plan_desc')),
            Stat::make(__('registration.admin.stats.without_plan'), $f->unknown)
                ->description(__('registration.admin.stats.without_plan_desc')),
            Stat::make(__('registration.admin.stats.checked_in'), $data->checkedIn)
                ->description(__('registration.admin.stats.checked_in_desc', ['expected' => $f->expectedArrivedByNow])),
            Stat::make(
                __('registration.admin.stats.free_spots'),
                $data->freeSpots === null ? (string) __('registration.admin.stats.unlimited') : $data->freeSpots,
            ),
        ];
    }
}
