<?php

namespace App\Modules\Tournaments\Filament\Resources\Tournaments\Tables;

use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Modules\Tournaments\Enums\TournamentStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TournamentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('tournaments.fields.name'))
                    ->searchable(),
                TextColumn::make('event.name')
                    ->label(__('tournaments.fields.event'))
                    ->searchable(),
                TextColumn::make('format')
                    ->label(__('tournaments.fields.format'))
                    ->badge()
                    ->formatStateUsing(fn (TournamentFormat $state) => $state->label()),
                TextColumn::make('status')
                    ->label(__('tournaments.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (TournamentStatus $state) => $state->label()),
                TextColumn::make('team_size')
                    ->label(__('tournaments.fields.team_size'))
                    ->toggleable(),
                TextColumn::make('starts_at')
                    ->label(__('tournaments.fields.starts_at'))
                    ->dateTime(),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                SelectFilter::make('event_id')
                    ->label(__('tournaments.fields.event'))
                    ->relationship('event', 'name'),
                SelectFilter::make('status')
                    ->label(__('tournaments.fields.status'))
                    ->options(fn () => collect(TournamentStatus::cases())
                        ->mapWithKeys(fn (TournamentStatus $status) => [$status->value => $status->label()])
                        ->all()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
