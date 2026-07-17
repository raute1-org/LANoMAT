<?php

declare(strict_types=1);

namespace App\Modules\CustomServers\Filament\Resources\CustomServers\Pages;

use App\Modules\CustomServers\Filament\Resources\CustomServers\CustomServerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomServer extends EditRecord
{
    protected static string $resource = CustomServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorize('delete'),
        ];
    }
}
