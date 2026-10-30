<?php

namespace App\Modules\Games\Filament\Resources\Games\Tables;

use App\Modules\Games\Models\Game;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class GamesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('games.fields.name'))
                    ->searchable(),
                TextColumn::make('slug')
                    ->label(__('games.fields.slug'))
                    ->searchable(),
                TextColumn::make('min_team_size')
                    ->label(__('games.fields.min_team_size')),
                TextColumn::make('max_team_size')
                    ->label(__('games.fields.max_team_size')),
                TextColumn::make('pelican_egg_id')
                    ->label(__('games.fields.pelican_egg_id'))
                    ->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make()
                    ->authorize('update'),
                DeleteAction::make()
                    ->authorize('delete')
                    ->before(function (DeleteAction $action, Game $record): void {
                        if ($record->isInUse()) {
                            Notification::make()->title(__('games.errors.in_use'))->danger()->send();
                            $action->halt();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function (DeleteBulkAction $action, Collection $records): void {
                            if ($records->contains(fn (Model $game): bool => $game instanceof Game && $game->isInUse())) {
                                Notification::make()->title(__('games.errors.in_use'))->danger()->send();
                                $action->halt();
                            }
                        }),
                ]),
            ]);
    }
}
