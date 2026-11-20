<?php

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Pages;

use App\Modules\GameRecipes\Actions\SyncRecipeFilesFromRequirements;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\Concerns\SyncsRequirementPins;
use App\Modules\GameRecipes\Filament\Resources\Recipes\RecipeResource;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Support\ReviewStamp;
use App\Modules\GameRecipes\Models\GameRecipe;
use Filament\Resources\Pages\CreateRecord;

class CreateRecipe extends CreateRecord
{
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
        if (! $this->record instanceof GameRecipe) {
            return;
        }

        app(SyncRecipeFilesFromRequirements::class)->handle($this->record);
        $this->applyRequirementPins($this->record);
    }
}
