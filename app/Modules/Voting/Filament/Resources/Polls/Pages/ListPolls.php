<?php

namespace App\Modules\Voting\Filament\Resources\Polls\Pages;

use App\Modules\Voting\Filament\Resources\Polls\PollResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPolls extends ListRecords
{
    protected static string $resource = PollResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
