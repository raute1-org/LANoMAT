<?php

namespace App\Modules\Schedule\Filament\Resources\ScheduleItems\Pages;

use App\Modules\Schedule\Filament\Resources\ScheduleItems\ScheduleItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListScheduleItems extends ListRecords
{
    protected static string $resource = ScheduleItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
