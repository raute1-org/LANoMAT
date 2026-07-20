<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Role;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('users.fields.name'))
                    ->searchable(),
                TextColumn::make('email')
                    ->label(__('users.fields.email'))
                    ->searchable(),
                TextColumn::make('role')
                    ->label(__('users.fields.role'))
                    ->badge()
                    ->formatStateUsing(fn (Role $state) => $state->label()),
                TextColumn::make('created_at')
                    ->label(__('users.fields.created_at'))
                    ->dateTime(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
