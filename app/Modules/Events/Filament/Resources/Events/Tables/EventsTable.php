<?php

namespace App\Modules\Events\Filament\Resources\Events\Tables;

use App\Modules\Events\Enums\EventStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('events.fields.name'))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('events.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (EventStatus $state) => $state->label()),
                TextColumn::make('starts_at')
                    ->label(__('events.fields.starts_at'))
                    ->dateTime(),
                TextColumn::make('location')
                    ->label(__('events.fields.location')),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                //
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
