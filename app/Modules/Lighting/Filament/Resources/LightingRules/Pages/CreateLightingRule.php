<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Filament\Resources\LightingRules\Pages;

use App\Modules\Lighting\Filament\Resources\LightingRules\LightingRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLightingRule extends CreateRecord
{
    protected static string $resource = LightingRuleResource::class;
}
