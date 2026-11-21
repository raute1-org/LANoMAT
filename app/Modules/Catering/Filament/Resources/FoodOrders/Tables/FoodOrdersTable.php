<?php

namespace App\Modules\Catering\Filament\Resources\FoodOrders\Tables;

use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Filament\Resources\FoodOrders\FoodOrderResource;
use App\Modules\Catering\Models\FoodOrder;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
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
                Action::make('group_order')
                    ->label(__('catering.group_order.action'))
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->authorize('update')
                    ->url(fn (FoodOrder $record): string => FoodOrderResource::getUrl('group-order', ['record' => $record])),
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
