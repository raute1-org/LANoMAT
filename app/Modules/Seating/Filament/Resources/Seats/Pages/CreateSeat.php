<?php

namespace App\Modules\Seating\Filament\Resources\Seats\Pages;

use App\Modules\Seating\Filament\Resources\Seats\SeatResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSeat extends CreateRecord
{
    protected static string $resource = SeatResource::class;
}
