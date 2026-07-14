<?php

namespace App\Modules\Tournaments\Filament\Resources\Tournaments\Pages;

use App\Modules\Tournaments\Filament\Resources\Tournaments\TournamentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTournament extends CreateRecord
{
    protected static string $resource = TournamentResource::class;
}
