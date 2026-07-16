<?php

namespace App\Modules\Tournaments\Filament\Resources\Tournaments\Schemas;

use App\Modules\Tournaments\Enums\TournamentFormat;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TournamentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('event_id')
                    ->label(__('tournaments.fields.event'))
                    ->relationship('event', 'name')
                    ->required()
                    ->searchable(),
                Select::make('game_id')
                    ->label(__('tournaments.fields.game'))
                    ->relationship('game', 'name')
                    ->searchable()
                    ->preload(),
                TextInput::make('name')
                    ->label(__('tournaments.fields.name'))
                    ->required()
                    ->maxLength(255),
                Select::make('format')
                    ->label(__('tournaments.fields.format'))
                    ->options(fn () => collect(TournamentFormat::cases())
                        ->mapWithKeys(fn (TournamentFormat $format) => [$format->value => $format->label()])
                        ->all())
                    ->required(),
                TextInput::make('team_size')
                    ->label(__('tournaments.fields.team_size'))
                    ->required()
                    ->numeric()
                    ->minValue(1),
                TextInput::make('max_entries')
                    ->label(__('tournaments.fields.max_entries'))
                    ->numeric(),
                DateTimePicker::make('starts_at')
                    ->label(__('tournaments.fields.starts_at'))
                    ->required(),
                DateTimePicker::make('checkin_opens_at')
                    ->label(__('tournaments.fields.checkin_opens_at')),
                DateTimePicker::make('checkin_closes_at')
                    ->label(__('tournaments.fields.checkin_closes_at')),
                KeyValue::make('settings')
                    ->label(__('tournaments.fields.settings')),
                // status/winner_entry_id are action-only (StartTournament,
                // bracket progression) and are deliberately not part of this
                // form, so mass assignment via the form can never write them.
            ]);
    }
}
