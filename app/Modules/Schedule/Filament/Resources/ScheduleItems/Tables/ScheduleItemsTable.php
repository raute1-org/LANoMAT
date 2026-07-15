<?php

namespace App\Modules\Schedule\Filament\Resources\ScheduleItems\Tables;

use App\Modules\Schedule\Enums\ScheduleItemType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ScheduleItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('schedule.fields.title'))
                    ->searchable(),
                TextColumn::make('type')
                    ->label(__('schedule.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (ScheduleItemType $state) => $state->label()),
                TextColumn::make('starts_at')
                    ->label(__('schedule.fields.starts_at'))
                    ->dateTime(),
                TextColumn::make('event.name')
                    ->label(__('schedule.fields.event'))
                    ->searchable(),
            ])
            ->defaultSort('starts_at')
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
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
