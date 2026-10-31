<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Filament\Resources\WebVoiceChannels\Pages;

use App\Modules\WebVoice\Filament\Resources\WebVoiceChannels\WebVoiceChannelResource;
use Filament\Resources\Pages\EditRecord;

class EditWebVoiceChannel extends EditRecord
{
    protected static string $resource = WebVoiceChannelResource::class;
}
