<?php

declare(strict_types=1);

namespace App\Modules\CustomServers\Filament\Resources\CustomServers\Schemas;

use App\Modules\Events\Models\Event;
use App\Modules\Hosts\Models\RemoteHost;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CustomServerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('customservers.fields.name'))
                    ->required()
                    ->maxLength(255),
                Select::make('remote_host_id')
                    ->label(__('customservers.fields.host'))
                    ->relationship('host', 'name')
                    ->options(fn (): array => RemoteHost::query()->pluck('name', 'id')->all())
                    ->searchable()
                    ->native(false)
                    ->required(),
                Select::make('event_id')
                    ->label(__('customservers.fields.event'))
                    ->relationship('event', 'name')
                    ->options(fn (): array => Event::query()->pluck('name', 'id')->all())
                    ->searchable()
                    ->native(false),
                TextInput::make('image')
                    ->label(__('customservers.fields.image'))
                    ->required()
                    ->maxLength(255)
                    ->extraInputAttributes(['class' => 'font-mono']),
                Textarea::make('command')
                    ->label(__('customservers.fields.command'))
                    ->rows(2)
                    ->extraInputAttributes(['class' => 'font-mono'])
                    ->columnSpanFull(),
                TextInput::make('ports')
                    ->label(__('customservers.fields.ports'))
                    ->placeholder('25565:25565')
                    ->extraInputAttributes(['class' => 'font-mono']),
                TextInput::make('container_name')
                    ->label(__('customservers.fields.container_name'))
                    ->required()
                    ->maxLength(255)
                    ->extraInputAttributes(['class' => 'font-mono']),

                // env is a plain string=>string map of docker -e values;
                // unlike Games\Domain\ServerPreset or Catering\Domain\MenuOption
                // (see roadmap insight #9), every value here is genuinely a
                // string (shell env vars have no other type), so Filament's
                // KeyValue component does not hit the "mangles jsonb types"
                // footgun those modules work around.
                KeyValue::make('env')
                    ->label(__('customservers.fields.env'))
                    ->keyLabel('Key')
                    ->valueLabel('Value')
                    ->columnSpanFull(),
            ]);
    }
}
