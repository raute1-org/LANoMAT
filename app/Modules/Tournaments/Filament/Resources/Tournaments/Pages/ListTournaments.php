<?php

namespace App\Modules\Tournaments\Filament\Resources\Tournaments\Pages;

use App\Modules\Tournaments\Filament\Resources\Tournaments\TournamentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTournaments extends ListRecords
{
    protected static string $resource = TournamentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
