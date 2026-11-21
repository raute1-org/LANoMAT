<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Filament\Resources\LightingRules\Pages;

use App\Modules\Lighting\Filament\Resources\LightingRules\LightingRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLightingRules extends ListRecords
{
    protected static string $resource = LightingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
