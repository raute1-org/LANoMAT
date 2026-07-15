<?php

namespace App\Modules\Schedule\Filament\Resources\ScheduleItems\Pages;

use App\Modules\Schedule\Filament\Resources\ScheduleItems\ScheduleItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateScheduleItem extends CreateRecord
{
    protected static string $resource = ScheduleItemResource::class;
}
