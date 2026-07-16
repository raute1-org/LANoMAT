<?php

namespace App\Modules\Infoscreen\Filament\Resources\TombolaPrizes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TombolaPrizeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('event_id')
                    ->label(__('infoscreen.fields.event'))
                    ->relationship('event', 'name')
                    ->required()
                    ->searchable(),
                TextInput::make('title')
                    ->label(__('infoscreen.fields.prize_title'))
                    ->required()
                    ->maxLength(255),
            ]);
    }
}
