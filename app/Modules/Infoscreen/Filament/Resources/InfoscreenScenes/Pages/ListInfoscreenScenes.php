<?php

namespace App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Pages;

use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\InfoscreenSceneResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInfoscreenScenes extends ListRecords
{
    protected static string $resource = InfoscreenSceneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
