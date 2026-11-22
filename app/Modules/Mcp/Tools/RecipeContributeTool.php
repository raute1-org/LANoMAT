<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\GameRecipes\Actions\ContributeRecipe;
use App\Modules\GameRecipes\Exceptions\RecipeException;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\LauncherSupport;
use App\Modules\Mcp\Support\ContributionCatalog;
use App\Modules\Mcp\Support\SafeText;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('recipe_contribute')]
#[Description('Contribute a new game recipe (draft) as the caller: slug, title, platforms, requirements and steps. Calling it again with the slug of your own draft REPLACES that draft: always send the complete recipe, a field you leave out is cleared. A portable recipe (one required `base` archive that only needs unpacking, a `file_exists` check and launch targets, with portable=true) is installable by the LANoMAT launcher; a recipe with step commands alone is not. An orga reviews the draft before it is published.')]
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
            'skill_description' => $schema->string()->description('What the skill does and when to use it (20 to 1024 characters).'),
            'skill_markdown' => $schema->string()->description('Full SKILL.md; a non-empty value replaces the generated text. The catalogue needs 60 to 150 lines (counted by splitting on newlines), YAML front matter with `name` equal to the slug and a non-empty `description` (max 1024 characters). Read lanomat://skills/contribute-recipe for a skeleton that passes. Stored as sent (plain Markdown; newline, carriage return and tab are the only control characters allowed).'),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $user = $this->user();

        Gate::forUser($user)->authorize('create', GameRecipe::class);

        // ContributeRecipe's own validator only caps the length of the skill
        // text; a control character other than newline, carriage return and
        // tab would travel into a public SKILL.md, so it is refused here,
        // before anything is stored. Same limit as the REST body (65535).
        Validator::make(
            ['skill_markdown' => $request->get('skill_markdown')],
            ['skill_markdown' => ['nullable', 'string', 'max:65535', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/']],
            ['skill_markdown.not_regex' => 'may only contain printable characters, newlines, carriage returns and tabs.'],
        )->validate();

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
            'skill_markdown' => $request->get('skill_markdown'),
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

        $catalog = app(ContributionCatalog::class)->for($recipe);

        return Response::make([Response::text($this->answerText($recipe, $catalog))])
            ->withStructuredContent([
                'recipe' => [
                    'slug' => $recipe->slug,
                    'title' => $recipe->title,
                    'status' => $recipe->status->value,
                    'launcher_supported' => app(LauncherSupport::class)->supports($recipe),
                    'pull_request' => $recipe->pullRequest(),
                    'review' => 'An orga reviews drafts under /admin before they are published.',
                ],
                'catalog' => $catalog,
            ]);
    }

    /**
     * One line for the model: the count and the first problems of the stored
     * draft (the list an assistant has to work through), bounded and on one
     * line because problem sentences carry contributor-authored labels.
     *
     * @param  array{pull_requests_enabled: bool, problems: list<string>, problems_when_published: list<string>}  $catalog
     */
    private function answerText(GameRecipe $recipe, array $catalog): string
    {
        $count = count($catalog['problems']);
        $head = 'Contributed "'.$recipe->slug.'" as a draft.';

        if ($count === 0) {
            return $head.' The catalogue text is ready for a pull request.';
        }

        return SafeText::line(sprintf(
            '%s The catalogue would refuse it (%d problem%s): %s Fix them and call recipe_contribute again with the COMPLETE recipe (every field, not only the changed ones: a call replaces the stored draft), until problems is empty.',
            $head,
            $count,
            $count === 1 ? '' : 's',
            implode('; ', array_slice($catalog['problems'], 0, 3)),
        ), 700) ?? $head;
    }
}
