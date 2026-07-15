<?php

namespace App\Modules\Catering\Filament\Resources\FoodOrders\Tables;

use App\Modules\Catering\Enums\FoodOrderStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FoodOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('catering.fields.title'))
                    ->searchable(),
                TextColumn::make('event.name')
                    ->label(__('catering.fields.event'))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('catering.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (FoodOrderStatus $state) => $state->label()),
                TextColumn::make('opens_at')
                    ->label(__('catering.fields.opens_at'))
                    ->dateTime(),
                TextColumn::make('closes_at')
                    ->label(__('catering.fields.closes_at'))
                    ->dateTime(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->authorize('update'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
