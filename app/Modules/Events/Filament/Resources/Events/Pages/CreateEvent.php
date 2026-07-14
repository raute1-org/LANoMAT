<?php

namespace App\Modules\Events\Filament\Resources\Events\Pages;

use App\Modules\Events\Filament\Resources\Events\EventResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;
}
