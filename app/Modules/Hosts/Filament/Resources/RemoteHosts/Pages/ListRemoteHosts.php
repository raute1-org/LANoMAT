<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Filament\Resources\RemoteHosts\Pages;

use App\Modules\Hosts\Filament\Resources\RemoteHosts\RemoteHostResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRemoteHosts extends ListRecords
{
    protected static string $resource = RemoteHostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->authorize('create'),
        ];
    }
}
