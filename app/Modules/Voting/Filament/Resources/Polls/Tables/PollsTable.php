<?php

namespace App\Modules\Voting\Filament\Resources\Polls\Tables;

use App\Modules\Voting\Enums\PollStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PollsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('question')
                    ->label(__('polls.fields.question'))
                    ->searchable(),
                TextColumn::make('event.name')
                    ->label(__('polls.fields.event'))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('polls.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (PollStatus $state) => $state->label()),
                TextColumn::make('closes_at')
                    ->label(__('polls.fields.closes_at'))
                    ->dateTime(),
                TextColumn::make('votes_count')
                    ->label(__('polls.fields.votes_count'))
                    ->counts('votes'),
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
