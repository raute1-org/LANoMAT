<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Filament\Resources\LightingScenes\Pages;

use App\Modules\Lighting\Filament\Resources\LightingScenes\LightingSceneResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLightingScene extends EditRecord
{
    protected static string $resource = LightingSceneResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return LightingSceneResource::explode($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return LightingSceneResource::prune($data);
    }
}
