<?php

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Pages;

use App\Modules\GameRecipes\Actions\PublishRecipe;
use App\Modules\GameRecipes\Actions\SyncRecipeFilesFromRequirements;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\Concerns\ResolvesReviewer;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\Concerns\SyncsRequirementPins;
use App\Modules\GameRecipes\Filament\Resources\Recipes\RecipeResource;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Support\ReviewStamp;
use App\Modules\GameRecipes\Models\GameRecipe;
use Filament\Resources\Pages\CreateRecord;

class CreateRecipe extends CreateRecord
{
    use ResolvesReviewer;
    use SyncsRequirementPins;

    protected static string $resource = RecipeResource::class;

    /**
     * A recipe authored in the panel is not disposable bundled content: the
     * `panel` source keeps `lanomat:recipes:sync --prune` (which only prunes
     * its own source kind) from deleting it. Publishing straight from the
     * create form is a transition into Published and gets a review stamp.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->captureRequirementPins($data);

        $data['source'] = GameRecipe::SOURCE_PANEL;
        $data['source_ref'] = null;

        return ReviewStamp::forTransition($data, null);
    }

    protected function afterCreate(): void
    {
        $record = $this->record;

        if (! $record instanceof GameRecipe) {
            return;
        }

        app(SyncRecipeFilesFromRequirements::class)->handle($record);
        $this->applyRequirementPins($record);

        // A recipe created as published is a release like any other: the pull
        // request pipeline hears about it exactly once.
        if ($record->status === RecipeStatus::Published) {
            app(PublishRecipe::class)->releaseAfterFormSave($record, $this->reviewer());
        }
    }
}
