<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Resources;

use App\Modules\GameRecipes\Models\GameRecipe;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

/**
 * `lanomat://recipes/{slug}/skill` — the exact SKILL.md the agent writes to
 * ~/.agents/skills/lanomat-<slug>/. Resources let a pi session pull the
 * skill text without spending a tool call.
 */
#[Name('recipe-skill')]
#[Description('The Agent Skill (SKILL.md) for one LANoMAT game recipe.')]
#[MimeType('text/markdown')]
class RecipeSkillResource extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('lanomat://recipes/{slug}/skill');
    }

    public function shouldRegister(Request $request): bool
    {
        return app(Modules::class)->enabled(Module::GameRecipes);
    }

    public function handle(Request $request): Response
    {
        $slug = (string) $request->get('slug');
        $recipe = GameRecipe::query()->where('slug', $slug)->published()->first();

        if (! $recipe instanceof GameRecipe) {
            return Response::error('Recipe not found.');
        }

        return Response::text((string) $recipe->skill_markdown);
    }
}
