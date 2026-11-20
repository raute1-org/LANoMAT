<?php

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Pages;

use App\Modules\GameRecipes\Actions\SyncRecipeFilesFromRequirements;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Filament\Resources\Recipes\RecipeResource;
use App\Modules\GameRecipes\Models\GameRecipe;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRecipe extends EditRecord
{
    protected static string $resource = RecipeResource::class;

    protected function afterSave(): void
    {
        if ($this->record instanceof GameRecipe) {
            app(SyncRecipeFilesFromRequirements::class)->handle($this->record);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label(__('game_recipes.actions.publish'))
                ->visible(fn (GameRecipe $record): bool => $record->status !== RecipeStatus::Published)
                ->requiresConfirmation()
                ->action(function (GameRecipe $record): void {
                    $record->forceFill([
                        'status' => RecipeStatus::Published,
                        'reviewed_by' => auth()->id(),
                        'reviewed_at' => now(),
                    ])->save();

                    $this->refreshFormData(['status']);
                }),
            Action::make('archive')
                ->label(__('game_recipes.actions.archive'))
                ->visible(fn (GameRecipe $record): bool => $record->status === RecipeStatus::Published)
                ->requiresConfirmation()
                ->action(function (GameRecipe $record): void {
                    $record->forceFill(['status' => RecipeStatus::Archived])->save();
                    $this->refreshFormData(['status']);
                }),
            DeleteAction::make(),
        ];
    }
}
