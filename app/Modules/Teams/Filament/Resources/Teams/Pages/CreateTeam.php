<?php

namespace App\Modules\Teams\Filament\Resources\Teams\Pages;

use App\Modules\Teams\Filament\Resources\Teams\TeamResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTeam extends CreateRecord
{
    protected static string $resource = TeamResource::class;
}
