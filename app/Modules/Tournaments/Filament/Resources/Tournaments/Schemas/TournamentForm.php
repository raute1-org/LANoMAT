<?php

namespace App\Modules\Tournaments\Filament\Resources\Tournaments\Schemas;

use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Support\StandingsView;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class TournamentForm
{
    /** @return list<Text> */
    private static function standingsLines(?Tournament $record): array
    {
        if ($record === null) {
            return [];
        }

        $rows = StandingsView::forTournament($record) ?? [];

        if ($rows === []) {
            return [Text::make(__('tournaments.standings.empty'))];
        }

        return array_map(
            fn (array $row): Text => Text::make(sprintf(
                '%d. %s: %d %s, %d %s, %d:%d (%+d)',
                $row['rank'],
                $row['name'],
                $row['wins'],
                __('tournaments.standings.wins'),
                $row['losses'],
                __('tournaments.standings.losses'),
                $row['pointsFor'],
                $row['pointsAgainst'],
                $row['scoreDifference'],
            )),
            $rows,
        );
    }

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
                    ->label(__('tournaments.fields.checkin_closes_at'))
                    ->after('checkin_opens_at')
                    ->validationMessages(['after' => __('tournaments.errors.checkin_closes_after_opens')]),
                KeyValue::make('settings')
                    ->label(__('tournaments.fields.settings')),
                // Round robin: the derived table, read-only (Filament-native text lines).
                Section::make(__('tournaments.standings.title'))
                    ->description(__('tournaments.standings.tiebreak'))
                    ->columnSpanFull()
                    ->visible(fn (?Tournament $record): bool => $record !== null
                        && $record->format === TournamentFormat::RoundRobin
                        && in_array($record->status, [TournamentStatus::Live, TournamentStatus::Finished], true))
                    ->schema(fn (?Tournament $record): array => self::standingsLines($record)),
                // status/winner_entry_id are action-only (StartTournament,
                // bracket progression) and are deliberately not part of this
                // form, so mass assignment via the form can never write them.
            ]);
    }
}
