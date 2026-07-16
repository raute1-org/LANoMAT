<?php

namespace App\Modules\Infoscreen\Filament\Resources\TombolaPrizes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TombolaPrizesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('infoscreen.fields.prize_title'))
                    ->searchable(),
                TextColumn::make('event.name')
                    ->label(__('infoscreen.fields.event'))
                    ->searchable(),
                TextColumn::make('draw.registration.user.name')
                    ->label(__('infoscreen.triggers.tombola_draw_title'))
                    ->placeholder('—'),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->filters([
                //
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
