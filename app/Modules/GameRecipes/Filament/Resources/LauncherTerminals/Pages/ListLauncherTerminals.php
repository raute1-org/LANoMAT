<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Filament\Resources\LauncherTerminals\Pages;

use App\Modules\GameRecipes\Filament\Resources\LauncherTerminals\LauncherTerminalResource;
use Filament\Resources\Pages\ListRecords;

class ListLauncherTerminals extends ListRecords
{
    protected static string $resource = LauncherTerminalResource::class;

    /** No create action: a terminal exists by pairing its launcher, not by hand. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
