<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Filament\Resources\ServerLinks\Schemas;

use App\Modules\GameServers\Enums\ServerLinkStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * `pelican_server_id`/`status` are provisioning state (see ServerLink's own
 * comment) and stay read-only here — an orga can flip `manual` and correct
 * the join-info fields (the same manual-fallback shape SetManualJoinInfo
 * writes), but never hand-forges the Pelican link itself. Flat `join_info.*`
 * keys mirror Games' default_server_config handling (see GameForm/EditGame).
 */
class ServerLinkForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('pelican_server_id')
                    ->label(__('gameservers.fields.pelican_server_id'))
                    ->disabled()
                    ->dehydrated(false),
                Select::make('status')
                    ->label(__('gameservers.fields.status'))
                    ->options(collect(ServerLinkStatus::cases())->mapWithKeys(
                        fn (ServerLinkStatus $status) => [$status->value => $status->label()],
                    ))
                    ->disabled()
                    ->dehydrated(false),
                Toggle::make('manual')
                    ->label(__('gameservers.fields.manual')),
                TextInput::make('address')
                    ->label(__('gameservers.fields.address'))
                    ->maxLength(255),
                TextInput::make('port')
                    ->label(__('gameservers.fields.port'))
                    ->numeric()
                    ->integer(),
                TextInput::make('password')
                    ->label(__('gameservers.fields.password'))
                    ->maxLength(255),
                TextInput::make('connect_string')
                    ->label(__('gameservers.fields.connect_string'))
                    ->maxLength(255),
            ]);
    }
}
