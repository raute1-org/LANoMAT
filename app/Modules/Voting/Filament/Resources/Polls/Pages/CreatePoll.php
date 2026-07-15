<?php

namespace App\Modules\Voting\Filament\Resources\Polls\Pages;

use App\Modules\Voting\Filament\Resources\Polls\PollResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePoll extends CreateRecord
{
    protected static string $resource = PollResource::class;
}
