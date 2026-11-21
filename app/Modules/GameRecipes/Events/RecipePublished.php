<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Events;

use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Foundation\Events\Dispatchable;

/** An orga released a recipe to the players. Dispatched after the write is committed. */
final class RecipePublished
{
    use Dispatchable;

    public function __construct(
        public readonly GameRecipe $recipe,
        public readonly int $reviewerId,
    ) {}
}
