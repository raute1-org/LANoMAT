<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Filament\Resources\WebVoiceChannels\Pages;

use App\Modules\WebVoice\Filament\Resources\WebVoiceChannels\WebVoiceChannelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWebVoiceChannels extends ListRecords
{
    protected static string $resource = WebVoiceChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
