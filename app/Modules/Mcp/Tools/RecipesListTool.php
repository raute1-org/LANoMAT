<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\LauncherLineup;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('recipes_list')]
#[Description('Published game recipes (UT2004, Ridge Racer, ...). Use this when the user asks what games can be installed, or before recipe_get / recipe_install_plan.')]
#[IsReadOnly]
class RecipesListTool extends LanomatTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::GameRecipes);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Title, slug or alias fragment (e.g. "ut2004").'),
            'platform' => $schema->string()->enum(['windows', 'linux', 'macos'])->description('Filter by target OS.'),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $query = mb_strtolower(trim((string) $request->get('query', '')));
        $platform = $request->get('platform');
        $user = $this->user();

        $recipes = app(LauncherLineup::class)
            ->filter(GameRecipe::query()
                ->visibleTo($user)
                ->orderBy('title')
                ->get())
            ->when($query !== '', fn ($collection) => $collection->filter(
                fn (GameRecipe $recipe): bool => collect($recipe->searchTerms())->contains(
                    static fn (string $term): bool => str_contains($term, $query),
                ),
            ))
            ->when(is_string($platform) && $platform !== '', fn ($collection) => $collection->filter(
                static fn (GameRecipe $recipe): bool => in_array($platform, $recipe->platforms ?? [], true),
            ))
            ->values();

        return $this->structured([
            'recipes' => $recipes->map(fn (GameRecipe $recipe): array => [
                'slug' => $recipe->slug,
                'title' => $recipe->title,
                'summary' => $recipe->summary,
                'version' => $recipe->version,
                'status' => $recipe->status->value,
                'platforms' => $recipe->platforms,
                'aliases' => $recipe->aliases,
                'in_lineup' => $recipe->in_lineup,
                // A contribution's pull request travels only with the caller's
                // own draft; another participant's is none of their business.
                'pull_request' => $recipe->contributor_user_id === $user->id
                    ? $recipe->pullRequest()
                    : null,
            ])->all(),
        ]);
    }
}
