<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Filament\Resources\LightingScenes\Pages;

use App\Modules\Lighting\Filament\Resources\LightingScenes\LightingSceneResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLightingScene extends CreateRecord
{
    protected static string $resource = LightingSceneResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return LightingSceneResource::prune($data);
    }
}
