<?php

namespace App\Modules\Teams\Filament\Resources\Teams\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('teams.fields.name'))
                    ->searchable(),
                TextColumn::make('tag')
                    ->label(__('teams.fields.tag'))
                    ->searchable(),
                TextColumn::make('owner.name')
                    ->label(__('teams.fields.owner')),
                TextColumn::make('members_count')
                    ->label(__('teams.fields.members_count'))
                    ->counts('members'),
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
