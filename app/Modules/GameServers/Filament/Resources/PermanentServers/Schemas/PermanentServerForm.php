<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Filament\Resources\PermanentServers\Schemas;

use App\Modules\GameServers\Enums\QueryProtocol;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Edit form of a permanent server: what participants see (name, description) and
 * where to join. The source, node and container link are fixed at creation.
 */
class PermanentServerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label(__('gameservers.permanent.fields.title'))
                    ->required()
                    ->maxLength(64),
                Textarea::make('description')
                    ->label(__('gameservers.permanent.fields.description'))
                    ->helperText(__('gameservers.permanent.fields.description_help'))
                    ->rows(3)
                    ->maxLength(1000)
                    ->columnSpanFull(),
                TextInput::make('address')
                    ->label(__('gameservers.permanent.fields.address'))
                    ->maxLength(255)
                    ->extraInputAttributes(['class' => 'font-mono']),
                TextInput::make('port')
                    ->label(__('gameservers.permanent.fields.port'))
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->extraInputAttributes(['class' => 'font-mono']),
                TextInput::make('connect_string')
                    ->label(__('gameservers.permanent.fields.connect_string'))
                    ->maxLength(255)
                    ->extraInputAttributes(['class' => 'font-mono']),
                Select::make('query_protocol')
                    ->label(__('gameservers.permanent.fields.query_protocol'))
                    ->options(collect(QueryProtocol::cases())->mapWithKeys(
                        fn (QueryProtocol $protocol): array => [$protocol->value => $protocol->label()],
                    ))
                    ->placeholder(__('gameservers.permanent.fields.query_protocol_none'))
                    ->native(false),
            ]);
    }
}
