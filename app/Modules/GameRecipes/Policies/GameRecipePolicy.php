<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Policies;

use App\Models\User;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Models\GameRecipe;

/** Recipes are orga-curated reference data; contributors only create drafts. */
class GameRecipePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, GameRecipe $recipe): bool
    {
        return $user->isOrga() || $recipe->isPublic() || $recipe->contributor_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        // Anyone may propose a draft (MCP recipe_contribute). Panel entry is
        // gated by viewAny(), so this alone never opens the admin resource.
        return true;
    }

    public function update(User $user, GameRecipe $recipe): bool
    {
        return $user->isOrga()
            || ($recipe->contributor_user_id === $user->id && $recipe->status === RecipeStatus::Draft);
    }

    public function delete(User $user, GameRecipe $recipe): bool
    {
        return $user->isOrga();
    }

    /** Filament's bulk delete authorizes `deleteAny`, not one record at a time. */
    public function deleteAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function review(User $user, GameRecipe $recipe): bool
    {
        return $user->isOrga();
    }

    /** Class-level form of review(), for gates that decide before a record exists. */
    public function reviewAny(User $user): bool
    {
        return $user->isOrga();
    }
}
