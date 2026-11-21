<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Support\RecipeFileMatcher;
use App\Modules\GameRecipes\Support\SafeText;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('recipe_get')]
#[Description('One recipe in full: requirements, steps, each required file with its LAN match, aliases and the skill name. Set include_skill for the complete SKILL.md.')]
#[IsReadOnly]
class RecipeGetTool extends LanomatTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::GameRecipes);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('Recipe slug or alias (e.g. ut2004).')->required(),
            'include_skill' => $schema->boolean()->description('Include the full SKILL.md text.')->default(false),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $matcher = app(RecipeFileMatcher::class);
        $needle = mb_strtolower((string) $request->get('slug'));

        $recipe = GameRecipe::query()
            ->visibleTo($this->user())
            ->get()
            ->first(fn (GameRecipe $candidate): bool => in_array($needle, $candidate->searchTerms(), true)
                || $candidate->slug === $needle);

        abort_unless($recipe instanceof GameRecipe, 404, 'Recipe not found.');

        $matches = $matcher->matchesFor($recipe, $this->user());

        return $this->structured([
            'recipe' => [
                'slug' => $recipe->slug,
                'title' => $recipe->title,
                'summary' => $recipe->summary,
                'description' => $recipe->description,
                'version' => $recipe->version,
                'status' => $recipe->status->value,
                'platforms' => $recipe->platforms,
                'aliases' => $recipe->aliases,
                'homepage' => $recipe->homepage,
                'license' => $recipe->license,
                // The MCP request wrapper has no host; the HTTP request does, and a
                // tool always runs inside one (streamable HTTP transport).
                'play_url' => $recipe->playUrlFor(request()->getHost()),
                'launch_target' => $recipe->launchTarget(),
                'launch_targets' => $recipe->launchTargets(),
                'supports_multiplayer' => $recipe->supportsMultiplayer(),
                'install_command' => $recipe->installCommand(),
                'launch_command' => $recipe->launchCommand(),
                'requirements' => $recipe->requirements,
                'steps' => $recipe->steps,
                'files' => $recipe->files->map(fn (GameRecipeFile $file): array => [
                    'id' => $file->id,
                    'role' => $file->role->value,
                    'label' => $file->label,
                    'match' => $file->patterns(),
                    'required' => $file->required,
                    'sha256' => $file->sha256,
                    'url' => $file->url,
                    'lan_match' => isset($matches[$file->id]) ? [
                        'shared_file_id' => $matches[$file->id]->id,
                        'name' => SafeText::fileName($matches[$file->id]->original_name),
                        'size_bytes' => $matches[$file->id]->size_bytes,
                    ] : null,
                ])->all(),
                'skill_markdown' => (bool) $request->get('include_skill', false) ? $recipe->skill_markdown : null,
            ],
        ]);
    }
}
