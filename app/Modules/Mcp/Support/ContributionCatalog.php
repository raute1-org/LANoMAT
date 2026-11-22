<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Support;

use App\Modules\GameRecipes\Actions\ProposeRecipe;
use App\Modules\GameRecipes\Contracts\RecipeRepository;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Models\GameRecipe;

/**
 * The `catalog` object of a contribution answer, the same for the MCP tool and
 * the REST endpoint: whether this installation proposes recipes at all, what
 * the git catalogue would refuse now, and what it would still refuse once the
 * orga published. `preview()` reads the recipe's own text and writes nothing,
 * so asking is free and tells a contributor nothing they did not write.
 */
final readonly class ContributionCatalog
{
    public function __construct(
        private ProposeRecipe $propose,
        private RecipeRepository $repository,
    ) {}

    /**
     * @return array{pull_requests_enabled: bool, problems: list<string>, problems_when_published: list<string>}
     */
    public function for(GameRecipe $recipe): array
    {
        return [
            'pull_requests_enabled' => $this->repository->isConfigured(),
            'problems' => $this->propose->preview($recipe),
            'problems_when_published' => $this->propose->preview($recipe, RecipeStatus::Published),
        ];
    }
}
