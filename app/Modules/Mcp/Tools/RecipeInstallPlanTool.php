<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\BuildInstallPlan;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('recipe_install_plan')]
#[Description('Build the install plan for a recipe on the user\'s OS: ordered steps, LAN download URLs, optional avatars and what is missing. Prefer LAN copies; external downloads are marked and need the user\'s OK.')]
class RecipeInstallPlanTool extends LanomatTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::GameRecipes);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('Recipe slug or alias.')->required(),
            'os' => $schema->string()->enum(['windows', 'linux', 'macos'])->default('windows'),
            'event_slug' => $schema->string()->description('Restrict LAN file matching to one event.'),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $plan = app(BuildInstallPlan::class);
        $needle = mb_strtolower((string) $request->get('slug'));
        $recipe = GameRecipe::query()
            ->visibleTo($this->user())
            ->get()
            ->first(fn (GameRecipe $candidate): bool => in_array($needle, $candidate->searchTerms(), true)
                || $candidate->slug === $needle);

        abort_unless($recipe instanceof GameRecipe, 404, 'Recipe not found.');

        $slug = $request->get('event_slug');
        $event = is_string($slug) && $slug !== '' ? $this->eventFromSlug($slug) : null;

        return $this->structured($plan->handle(
            $recipe,
            $this->user(),
            (string) $request->get('os', 'windows'),
            $event,
        ));
    }
}
