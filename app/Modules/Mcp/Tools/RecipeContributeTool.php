<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\GameRecipes\Actions\ContributeRecipe;
use App\Modules\GameRecipes\Exceptions\RecipeException;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\LauncherSupport;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('recipe_contribute')]
#[Description('Contribute a new game recipe (draft) as the caller: slug, title, platforms, requirements and steps. A portable recipe (one required `base` archive that only needs unpacking, a `file_exists` check and launch targets, with portable=true) is installable by the LANoMAT launcher; a recipe with step commands alone is not. An orga reviews the draft before it is published.')]
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
            'version' => $schema->string()->description('Recipe version, e.g. "1.0.0" or a game patch level (max 40).'),
            'homepage' => $schema->string()->description('http(s) URL for the game or its project page (max 500).'),
            'license' => $schema->string()->description('License note, e.g. "Freeware; archive is user-supplied" (max 200).'),
            'platforms' => $schema->array()->items($schema->string())->description('windows/linux/macos.'),
            'aliases' => $schema->array()->items($schema->string()),
            'requires' => $schema->array()->description('Same shape as recipe.yaml requires: role, label, match[], required, os, notes, size_bytes, sha256, url. Set shared_file_id to pin a LAN file the caller can open (their own upload or an approved one).'),
            'steps' => $schema->array()->description('Same shape as recipe.yaml steps: id, title, os, optional, detail, commands[].'),
            'launch_targets' => $schema->array()->description('How to start the installed game: [{id, label, os: windows|linux|macos|any, command, shell?: powershell|cmd|bash, notes?}]. Max 10.'),
            'play_url' => $schema->string()->description('http(s) URL for a browser recipe; makes it launcher-supported without an install.'),
            'portable' => $schema->boolean()->description('True when the required base archive only needs unpacking (a portable recipe); becomes metadata.portable.'),
            'skill_description' => $schema->string()->description('What the skill does and when to use it.'),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $user = $this->user();

        Gate::forUser($user)->authorize('create', GameRecipe::class);

        $payload = [
            'slug' => (string) $request->get('slug'),
            'title' => (string) $request->get('title'),
            'summary' => $request->get('summary'),
            'description' => $request->get('description'),
            'version' => $request->get('version'),
            'homepage' => $request->get('homepage'),
            'license' => $request->get('license'),
            'play_url' => $request->get('play_url'),
            'platforms' => (array) $request->get('platforms', ['windows']),
            'aliases' => (array) $request->get('aliases', []),
            // `requires` is the canonical recipe key; the older
            // `requirements` name stays accepted for existing clients.
            'requirements' => (array) ($request->get('requires') ?? $request->get('requirements', [])),
            'steps' => (array) $request->get('steps', []),
            'launch_targets' => (array) $request->get('launch_targets', []),
            'via' => 'mcp',
            'skill_description' => $request->get('skill_description'),
        ];

        // The schema exposes the one metadata flag a contribution may set.
        if (is_bool($request->get('portable'))) {
            $payload['metadata'] = ['portable' => $request->get('portable')];
        }

        $contribute = app(ContributeRecipe::class);

        try {
            $recipe = $contribute->handle($user, $payload);
        } catch (RecipeException $exception) {
            // The validator collects every problem in one pass; give the model
            // the whole list so it can fix the payload in one round.
            return Response::error($exception->summary());
        }

        return $this->structured([
            'recipe' => [
                'slug' => $recipe->slug,
                'title' => $recipe->title,
                'status' => $recipe->status->value,
                'launcher_supported' => app(LauncherSupport::class)->supports($recipe),
                'pull_request' => $recipe->pullRequest(),
                'review' => 'An orga reviews drafts under /admin before they are published.',
            ],
        ]);
    }
}
