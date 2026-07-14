<?php

namespace App\Modules\Seating\Filament\Resources\Seats\Pages;

use App\Modules\Seating\Filament\Resources\Seats\SeatResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSeat extends EditRecord
{
    protected static string $resource = SeatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
