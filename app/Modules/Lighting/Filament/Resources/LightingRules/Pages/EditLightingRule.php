<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Filament\Resources\LightingRules\Pages;

use App\Modules\Lighting\Filament\Resources\LightingRules\LightingRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLightingRule extends EditRecord
{
    protected static string $resource = LightingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
