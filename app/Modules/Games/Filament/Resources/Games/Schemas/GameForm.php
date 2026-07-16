<?php

namespace App\Modules\Games\Filament\Resources\Games\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class GameForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('games.fields.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label(__('games.fields.slug'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                FileUpload::make('icon_path')
                    ->label(__('games.fields.icon'))
                    ->disk('public')
                    ->directory('game-icons')
                    ->image(),
                TextInput::make('min_team_size')
                    ->label(__('games.fields.min_team_size'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(1),
                TextInput::make('max_team_size')
                    ->label(__('games.fields.max_team_size'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(1),
                TextInput::make('pelican_egg_id')
                    ->label(__('games.fields.pelican_egg_id'))
                    ->maxLength(255),

                // Typed default_server_config fields. `default_server_config`
                // itself is not fillable (see Game::$fillable), so these flat
                // keys are marshalled into a ServerConfig by the Create/Edit
                // pages, mirroring Infoscreen's config/SceneConfig handling.
                TextInput::make('max_players')
                    ->label(__('games.fields.max_players'))
                    ->numeric()
                    ->integer()
                    ->minValue(1),
                TextInput::make('map')
                    ->label(__('games.fields.map'))
                    ->maxLength(255),
                TextInput::make('password')
                    ->label(__('games.fields.password'))
                    ->maxLength(255),
            ]);
    }
}
