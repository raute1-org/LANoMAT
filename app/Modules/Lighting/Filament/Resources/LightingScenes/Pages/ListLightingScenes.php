<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Filament\Resources\LightingScenes\Pages;

use App\Modules\Lighting\Filament\Resources\LightingScenes\LightingSceneResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLightingScenes extends ListRecords
{
    protected static string $resource = LightingSceneResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
