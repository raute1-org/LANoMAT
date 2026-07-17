<?php

declare(strict_types=1);

namespace App\Modules\Voice\Filament\Resources\VoiceClientInstallers\Pages;

use App\Modules\Voice\Filament\Resources\VoiceClientInstallers\VoiceClientInstallerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVoiceClientInstallers extends ListRecords
{
    protected static string $resource = VoiceClientInstallerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->authorize('create'),
        ];
    }
}
