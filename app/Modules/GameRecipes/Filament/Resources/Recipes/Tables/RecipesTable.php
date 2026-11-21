<?php

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Tables;

use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Support\ContributionFiles;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Support\ContributionFileState;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Support\PullRequestColor;
use App\Modules\GameRecipes\Models\GameRecipe;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                TextColumn::make('contributor.name')
                    ->label(__('game_recipes.fields.contributor'))
                    ->placeholder('—')
                    ->toggleable(),
                // The file state of a shared game: "wartet auf Freigabe",
                // "abgelehnt" or "fehlt". Decided from the eager-loaded
                // `files.sharedFile` only, so a row costs no query.
                TextColumn::make('contribution_file_state')
                    ->label(__('game_recipes.fields.file_state'))
                    ->badge()
                    ->state(fn (GameRecipe $record): ?ContributionFileState => ContributionFiles::state($record))
                    ->formatStateUsing(fn (?ContributionFileState $state): ?string => $state?->label())
                    ->color(fn (?ContributionFileState $state): ?string => $state?->color())
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('in_lineup')
                    ->label(__('game_recipes.fields.in_lineup'))
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->toggleable(),
                TextColumn::make('pr_state')
                    ->label(__('game_recipes.fields.pull_request'))
                    ->badge()
                    ->formatStateUsing(fn (PullRequestState $state): string => $state->label())
                    ->color(fn (PullRequestState $state): ?string => PullRequestColor::for($state))
                    // Only a state that exists in the repository has a page to
                    // open; a blocked or failed proposal never left LANoMAT.
                    ->url(fn (GameRecipe $record): ?string => $record->pr_state?->existsRemotely() === true
                        ? $record->pr_url
                        : null)
                    ->openUrlInNewTab()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('reviewed_at')
                    ->label(__('game_recipes.fields.reviewed'))
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('files_count')->label(__('game_recipes.fields.files'))->counts('files'),
                TextColumn::make('updated_at')->label(__('game_recipes.fields.updated'))->dateTime()->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(RecipeStatus::cases())->mapWithKeys(fn (RecipeStatus $s) => [$s->value => $s->label()])->all()),
                Filter::make('contributed')
                    ->label(__('game_recipes.filters.contributed'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('contributor_user_id')),
                TernaryFilter::make('in_lineup')
                    ->label(__('game_recipes.filters.in_lineup'))
                    ->placeholder(__('game_recipes.filters.in_lineup_any'))
                    ->trueLabel(__('game_recipes.filters.in_lineup_yes'))
                    ->falseLabel(__('game_recipes.filters.in_lineup_no')),
                SelectFilter::make('pr_state')
                    ->label(__('game_recipes.filters.pull_request'))
                    ->options(collect(PullRequestState::cases())->mapWithKeys(fn (PullRequestState $s) => [$s->value => $s->label()])->all()),
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
