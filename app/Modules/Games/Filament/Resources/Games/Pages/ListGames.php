<?php

namespace App\Modules\Games\Filament\Resources\Games\Pages;

use App\Modules\Games\Filament\Resources\Games\GameResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGames extends ListRecords
{
    protected static string $resource = GameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->authorize('create'),
        ];
    }
}
