<?php

namespace App\Modules\Infoscreen\Filament\Resources\TombolaPrizes\Pages;

use App\Modules\Infoscreen\Filament\Resources\TombolaPrizes\TombolaPrizeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTombolaPrizes extends ListRecords
{
    protected static string $resource = TombolaPrizeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
