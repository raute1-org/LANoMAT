<?php

namespace App\Modules\Infoscreen\Filament\Resources\TombolaPrizes\Pages;

use App\Modules\Infoscreen\Filament\Resources\TombolaPrizes\TombolaPrizeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTombolaPrize extends EditRecord
{
    protected static string $resource = TombolaPrizeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorize('delete'),
        ];
    }
}
