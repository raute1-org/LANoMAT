<?php

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Pages;

use App\Modules\GameRecipes\Actions\SyncRecipeFilesFromRequirements;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\Concerns\SyncsRequirementPins;
use App\Modules\GameRecipes\Filament\Resources\Recipes\RecipeResource;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Support\ReviewStamp;
use App\Modules\GameRecipes\Models\GameRecipe;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRecipe extends EditRecord
{
    use SyncsRequirementPins;

    protected static string $resource = RecipeResource::class;

    /**
     * The status can also be set through the form. It is stamped only on a
     * transition into Published (see ReviewStamp); saving a published recipe
     * again keeps the reviewer who released it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->captureRequirementPins($data);

        return ReviewStamp::forTransition(
            $data,
            $this->record instanceof GameRecipe ? $this->record->status : null,
        );
    }

    protected function afterSave(): void
    {
        if (! $this->record instanceof GameRecipe) {
            return;
        }

        app(SyncRecipeFilesFromRequirements::class)->handle($this->record);
        $this->applyRequirementPins($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Visible only while the recipe is not published, so both actions
            // are always a status transition; the policy decides who may make it.
            Action::make('publish')
                ->label(__('game_recipes.actions.publish'))
                ->visible(fn (GameRecipe $record): bool => $record->status !== RecipeStatus::Published)
                ->authorize('review')
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
                ->authorize('review')
                ->requiresConfirmation()
                ->action(function (GameRecipe $record): void {
                    // Archiving keeps the release stamp: it still says who
                    // published this recipe and when.
                    $record->forceFill(['status' => RecipeStatus::Archived])->save();
                    $this->refreshFormData(['status']);
                }),
            DeleteAction::make(),
        ];
    }
}
