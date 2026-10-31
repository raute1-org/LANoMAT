<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Filament\Resources\WebVoiceChannels\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class WebVoiceChannelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('web_voice.resource.name'))
                ->required()
                ->maxLength(80),
            TextInput::make('description')
                ->label(__('web_voice.resource.description'))
                ->maxLength(160),
        ]);
    }
}
