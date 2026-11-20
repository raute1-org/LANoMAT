<?php

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Pages;

use App\Modules\GameRecipes\Actions\SyncRecipeFilesFromRequirements;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Filament\Resources\Recipes\RecipeResource;
use App\Modules\GameRecipes\Models\GameRecipe;
use Filament\Resources\Pages\CreateRecord;

class CreateRecipe extends CreateRecord
{
    protected static string $resource = RecipeResource::class;

    /**
     * A recipe authored in the panel is not disposable bundled content: the
     * `panel` source keeps `lanomat:recipes:sync --prune` (which only prunes
     * its own source kind) from deleting it. Publishing straight from the
     * create form still records who released it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['source'] = 'panel';
        $data['source_ref'] = null;

        if (($data['status'] ?? null) === RecipeStatus::Published->value) {
            $data['reviewed_by'] = auth()->id();
            $data['reviewed_at'] = now();
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->record instanceof GameRecipe) {
            app(SyncRecipeFilesFromRequirements::class)->handle($this->record);
        }
    }
}
