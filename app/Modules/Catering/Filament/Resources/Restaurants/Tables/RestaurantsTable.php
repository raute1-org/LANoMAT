<?php

namespace App\Modules\Catering\Filament\Resources\Restaurants\Tables;

use App\Modules\Catering\Models\Restaurant;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RestaurantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('foodOrders'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('catering.restaurant.fields.name'))
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('logo')
                    ->label(__('catering.restaurant.fields.logo'))
                    ->boolean()
                    ->state(fn (Restaurant $record): bool => $record->logo !== null),
                TextColumn::make('dishes')
                    ->label(__('catering.restaurant.fields.dishes'))
                    ->state(fn (Restaurant $record): int => count($record->menu))
                    ->numeric()
                    ->alignEnd()
                    ->fontFamily('mono'),
                TextColumn::make('food_orders_count')
                    ->label(__('catering.restaurant.fields.rounds'))
                    ->numeric()
                    ->alignEnd()
                    ->fontFamily('mono')
                    ->sortable(),
                TextColumn::make('phone')
                    ->label(__('catering.restaurant.fields.phone'))
                    ->placeholder('–'),
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('catering.restaurant.table.empty'))
            ->emptyStateDescription(__('catering.restaurant.table.empty_help'))
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (Restaurant $record): bool => $record->food_orders_count === 0),
            ]);
    }
}
