<?php

namespace App\Modules\Teams\Filament\Resources\Teams\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TeamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('teams.fields.name'))
                    ->required()
                    ->maxLength(64),
                TextInput::make('tag')
                    ->label(__('teams.fields.tag'))
                    ->required()
                    ->maxLength(16),
                Select::make('owner_id')
                    ->label(__('teams.fields.owner'))
                    ->relationship('owner', 'name')
                    ->required()
                    ->searchable(),
                FileUpload::make('logo_path')
                    ->label(__('teams.fields.logo'))
                    ->disk('public')
                    ->directory('team-logos')
                    ->image()
                    ->maxSize(2048),
            ]);
    }
}
