<?php

namespace App\Modules\Schedule\Filament\Resources\ScheduleItems\Pages;

use App\Modules\Schedule\Filament\Resources\ScheduleItems\ScheduleItemResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditScheduleItem extends EditRecord
{
    protected static string $resource = ScheduleItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorize('delete'),
        ];
    }
}
