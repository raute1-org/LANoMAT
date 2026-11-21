<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\GameRecipes\Actions\ContributeRecipe;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('recipe_contribute')]
#[Description('Contribute a new game recipe (draft) as the caller: slug, title, platforms, requirements and steps. An orga reviews it before it is published.')]
class RecipeContributeTool extends LanomatTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::GameRecipes);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('lowercase-kebab-case id.')->required(),
            'title' => $schema->string()->required(),
            'summary' => $schema->string(),
            'description' => $schema->string(),
            'platforms' => $schema->array()->items($schema->string())->description('windows/linux/macos.'),
            'aliases' => $schema->array()->items($schema->string()),
            'requires' => $schema->array()->description('Same shape as recipe.yaml requires: role, label, match[], required.'),
            'steps' => $schema->array()->description('Same shape as recipe.yaml steps: id, title, os, optional, detail, commands[].'),
            'skill_description' => $schema->string()->description('What the skill does and when to use it.'),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $contribute = app(ContributeRecipe::class);
        $recipe = $contribute->handle($this->user(), [
            'slug' => (string) $request->get('slug'),
            'title' => (string) $request->get('title'),
            'summary' => $request->get('summary'),
            'description' => $request->get('description'),
            'platforms' => (array) $request->get('platforms', ['windows']),
            'aliases' => (array) $request->get('aliases', []),
            'requirements' => (array) ($request->get('requires') ?? $request->get('requirements', [])),
            'steps' => (array) $request->get('steps', []),
            'skill_description' => $request->get('skill_description'),
        ]);

        return $this->structured([
            'recipe' => [
                'slug' => $recipe->slug,
                'title' => $recipe->title,
                'status' => $recipe->status->value,
                'review' => 'An orga reviews drafts under /admin before they are published.',
            ],
        ]);
    }
}
