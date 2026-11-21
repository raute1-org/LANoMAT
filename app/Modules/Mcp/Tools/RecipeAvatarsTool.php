<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\AvatarCatalog;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('recipe_avatars')]
#[Description('Selectable avatar/content packs (VRM characters) for a recipe, with their LAN download status. The installer shows this as a multi-select and downloads only the chosen files from the LAN server.')]
#[IsReadOnly]
class RecipeAvatarsTool extends LanomatTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::GameRecipes);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('Recipe slug; omit for the global avatar library.'),
            'query' => $schema->string()->description('Filter by label or author.'),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $catalog = app(AvatarCatalog::class);
        $slug = $request->get('slug');
        $recipe = null;

        if (is_string($slug) && $slug !== '') {
            $needle = mb_strtolower($slug);
            $recipe = GameRecipe::query()
                ->visibleTo($this->user())
                ->get()
                ->first(fn (GameRecipe $candidate): bool => $candidate->slug === $needle
                    || in_array($needle, $candidate->searchTerms(), true));
            abort_unless($recipe instanceof GameRecipe, 404, 'Recipe not found.');
        }

        return $this->structured([
            'recipe' => $recipe?->slug,
            'avatars' => $catalog->forRecipe($recipe, $this->user(), $request->get('query')),
        ]);
    }
}
