<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Events;

use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A participant created or changed their draft recipe (web share, MCP tool or
 * REST). Dispatched after the write is committed.
 */
final class RecipeContributed
{
    use Dispatchable;

    public function __construct(
        public readonly GameRecipe $recipe,
        public readonly int $userId,
    ) {}
}
