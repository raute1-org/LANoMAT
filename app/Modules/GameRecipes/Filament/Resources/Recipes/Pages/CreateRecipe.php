<?php

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Pages;

use App\Modules\GameRecipes\Actions\SyncRecipeFilesFromRequirements;
use App\Modules\GameRecipes\Filament\Resources\Recipes\RecipeResource;
use App\Modules\GameRecipes\Models\GameRecipe;
use Filament\Resources\Pages\CreateRecord;

class CreateRecipe extends CreateRecord
{
    protected static string $resource = RecipeResource::class;

    protected function afterCreate(): void
    {
        if ($this->record instanceof GameRecipe) {
            app(SyncRecipeFilesFromRequirements::class)->handle($this->record);
        }
    }
}
