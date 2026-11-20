<?php

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Tables;

use App\Modules\GameRecipes\Enums\RecipeStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RecipesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label(__('game_recipes.fields.title'))->searchable()->sortable(),
                TextColumn::make('slug')->label(__('game_recipes.fields.slug'))->searchable()->toggleable(),
                TextColumn::make('version')->label(__('game_recipes.fields.version'))->toggleable(),
                TextColumn::make('status')
                    ->label(__('game_recipes.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (RecipeStatus $state) => $state->label()),
                TextColumn::make('files_count')->label(__('game_recipes.fields.files'))->counts('files'),
                TextColumn::make('updated_at')->label(__('game_recipes.fields.updated'))->dateTime()->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(RecipeStatus::cases())->mapWithKeys(fn (RecipeStatus $s) => [$s->value => $s->label()])->all()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
