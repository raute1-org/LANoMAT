<?php

namespace App\Modules\Seating\Filament\Resources\Seats\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SeatsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('event.name')
                    ->label(__('seating.fields.event'))
                    ->searchable(),
                TextColumn::make('label')
                    ->label(__('seating.fields.label'))
                    ->searchable(),
                TextColumn::make('pos_x')
                    ->label(__('seating.fields.pos_x')),
                TextColumn::make('pos_y')
                    ->label(__('seating.fields.pos_y')),
                TextColumn::make('assignment.registration.user.name')
                    ->label(__('seating.fields.occupied_by'))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('event_id')
                    ->label(__('seating.fields.event'))
                    ->relationship('event', 'name'),
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
