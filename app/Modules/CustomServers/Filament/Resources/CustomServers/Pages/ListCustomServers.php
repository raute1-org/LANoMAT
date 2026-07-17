<?php

declare(strict_types=1);

namespace App\Modules\CustomServers\Filament\Resources\CustomServers\Pages;

use App\Modules\CustomServers\Filament\Resources\CustomServers\CustomServerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomServers extends ListRecords
{
    protected static string $resource = CustomServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->authorize('create'),
        ];
    }
}
