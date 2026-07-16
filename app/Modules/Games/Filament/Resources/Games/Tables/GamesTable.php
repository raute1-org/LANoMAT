<?php

namespace App\Modules\Games\Filament\Resources\Games\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GamesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('games.fields.name'))
                    ->searchable(),
                TextColumn::make('slug')
                    ->label(__('games.fields.slug'))
                    ->searchable(),
                TextColumn::make('min_team_size')
                    ->label(__('games.fields.min_team_size')),
                TextColumn::make('max_team_size')
                    ->label(__('games.fields.max_team_size')),
                TextColumn::make('pelican_egg_id')
                    ->label(__('games.fields.pelican_egg_id'))
                    ->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make()
                    ->authorize('update'),
                DeleteAction::make()
                    ->authorize('delete'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
