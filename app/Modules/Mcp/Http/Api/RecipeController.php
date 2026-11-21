<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Http\Api;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\GameRecipes\Actions\ContributeRecipe;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Support\AvatarCatalog;
use App\Modules\GameRecipes\Support\BuildInstallPlan;
use App\Modules\GameRecipes\Support\RecipeFileMatcher;
use App\Modules\Mcp\Support\SafeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** The agent's REST surface for recipes: list, read, skill, plan, files, avatars, contribute. */
class RecipeController extends Controller
{
    use ResolvesAuthenticatedUser;

    /**
     * The one recipe the shipped launcher build can install: its engine still
     * verifies the UT2004 `System/` tree. The catalogue lists the other
     * published recipes anyway (their web page and their summary are useful);
     * they are just not launcher-installable yet. Keeping the fact here means
     * the launcher, the web page and every agent read the same flag instead of
     * each guessing from a slug.
     */
    public const LAUNCHER_PILOT_SLUG = 'unreal-tournament-2004';

    public function index(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        $query = mb_strtolower($request->string('query')->toString());

        $recipes = GameRecipe::query()
            ->visibleTo($user)
            ->orderBy('title')
            ->get()
            ->when($query !== '', fn ($collection) => $collection->filter(
                fn (GameRecipe $recipe): bool => collect($recipe->searchTerms())->contains(
                    static fn (string $term): bool => str_contains($term, $query),
                ),
            ))
            ->values();

        return response()->json([
            'data' => $recipes->map(fn (GameRecipe $recipe): array => [
                'slug' => $recipe->slug,
                'title' => $recipe->title,
                'summary' => $recipe->summary,
                'version' => $recipe->version,
                'status' => $recipe->status->value,
                'platforms' => $recipe->platforms,
                // Read-only capability flags: what a client may do with this
                // catalogue entry, without it having to open the plan first.
                'installable' => $this->installable($recipe),
                'launcher_supported' => $recipe->slug === self::LAUNCHER_PILOT_SLUG,
                'updated_at' => $recipe->updated_at?->toIso8601String(),
            ])->all(),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function show(Request $request, string $slug, RecipeFileMatcher $matcher): JsonResponse
    {
        $recipe = $this->findRecipe($request, $slug);
        $matches = $matcher->matchesFor($recipe, $this->authUser($request));

        return response()->json([
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
                // {{host}} is substituted with the address the agent reached,
                // so a recipe never hands localhost to a player.
                'play_url' => $recipe->playUrl($request->getHost()),
                'launch_target' => $recipe->launchTarget(),
                'launch_targets' => $this->launchTargets($recipe),
                'supports_multiplayer' => $recipe->supportsMultiplayer(),
                'install_command' => $recipe->installCommand(),
                'launch_command' => $recipe->launchCommand(),
                'requirements' => $recipe->requirements,
                'steps' => $recipe->steps,
                'files' => $recipe->files->map(fn (GameRecipeFile $file): array => [
                    'id' => $file->id,
                    'role' => $file->role->value,
                    'label' => SafeText::line($file->label) ?? '',
                    'os' => $file->os(),
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
            ],
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function skill(Request $request, string $slug): Response
    {
        $recipe = $this->findRecipe($request, $slug);

        return response((string) $recipe->skill_markdown, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function plan(Request $request, string $slug, BuildInstallPlan $plan): JsonResponse
    {
        $data = $request->validate([
            'os' => ['nullable', 'in:windows,linux,macos,win,mac'],
            'event_slug' => ['nullable', 'string', 'max:120'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $recipe = $this->findRecipe($request, $slug);
        $event = isset($data['event_slug'])
            ? Event::query()->publiclyVisible()->where('slug', $data['event_slug'])->firstOrFail()
            : null;

        return response()->json(
            $plan->handle(
                $recipe,
                $this->authUser($request),
                (string) ($data['os'] ?? 'windows'),
                $event,
                // A dry run must not write the install audit row.
                record: ! (bool) ($data['dry_run'] ?? false),
            ),
            200,
            ['Cache-Control' => 'no-store, private'],
        );
    }

    public function files(Request $request, string $slug, RecipeFileMatcher $matcher): JsonResponse
    {
        $recipe = $this->findRecipe($request, $slug);
        $matches = $matcher->matchesFor($recipe, $this->authUser($request));

        return response()->json([
            'data' => $recipe->files->map(fn (GameRecipeFile $file): array => [
                'id' => $file->id,
                'role' => $file->role->value,
                'label' => SafeText::line($file->label) ?? '',
                // A file for the other platform, so an agent can pick the
                // right patch instead of the first one in the list.
                'os' => $file->os(),
                'required' => $file->required,
                'match' => $file->patterns(),
                'status' => isset($matches[$file->id]) ? 'matched' : ($file->url !== null ? 'external' : 'missing'),
                'name' => SafeText::fileName($matches[$file->id]->original_name ?? null),
                'size_bytes' => $matches[$file->id]->size_bytes ?? $file->size_bytes,
                'sha256' => $file->sha256,
                'download_url' => isset($matches[$file->id])
                    ? route('mcp.files.download', ['sharedFile' => $matches[$file->id]->id], true)
                    : $file->url,
            ])->all(),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function avatars(Request $request, string $slug, AvatarCatalog $catalog): JsonResponse
    {
        $recipe = $this->findRecipe($request, $slug);

        return response()->json([
            'data' => $catalog->forRecipe($recipe, $this->authUser($request), $request->string('q')->toString()),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function contribute(Request $request, ContributeRecipe $contribute): JsonResponse
    {
        $user = $this->authUser($request);

        // Anyone may propose a draft; the policy decides, the same as the tool.
        Gate::forUser($user)->authorize('create', GameRecipe::class);

        $data = $request->validate([
            'slug' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:10000'],
            'platforms' => ['nullable', 'array', 'max:3'],
            'platforms.*' => ['string', 'in:windows,linux,macos'],
            'aliases' => ['nullable', 'array', 'max:20'],
            'aliases.*' => ['string', 'max:60'],
            'requires' => ['nullable', 'array', 'max:50'],
            'requirements' => ['nullable', 'array', 'max:50'],
            'steps' => ['nullable', 'array', 'max:50'],
            'skill_description' => ['nullable', 'string', 'max:1024'],
            'skill_markdown' => ['nullable', 'string', 'max:65535'],
        ]);

        $recipe = $contribute->handle($user, $data);

        return response()->json([
            'recipe' => [
                'slug' => $recipe->slug,
                'title' => $recipe->title,
                'status' => $recipe->status->value,
            ],
        ], 201, ['Cache-Control' => 'no-store, private']);
    }

    /** @return list<array<string, mixed>> */
    private function launchTargets(GameRecipe $recipe): array
    {
        return array_map(static function (array $target): array {
            $target['label'] = SafeText::line($target['label']) ?? '';
            $target['notes'] = SafeText::line($target['notes']) ?: null;

            return $target;
        }, $recipe->launchTargets());
    }

    /**
     * Whether the catalogue entry carries everything an install needs:
     * requirements, steps and at least one launch target. A published recipe
     * without them is a description, not an installable game, and the launcher
     * card must not offer a dead Install button for it.
     */
    private function installable(GameRecipe $recipe): bool
    {
        return ($recipe->requirements ?? []) !== []
            && ($recipe->steps ?? []) !== []
            && $recipe->launchTarget() !== null;
    }

    private function findRecipe(Request $request, string $slug): GameRecipe
    {
        $needle = mb_strtolower($slug);

        $recipe = GameRecipe::query()
            ->visibleTo($this->authUser($request))
            ->get()
            ->first(fn (GameRecipe $candidate): bool => $candidate->slug === $needle
                || in_array($needle, $candidate->searchTerms(), true));

        abort_unless($recipe instanceof GameRecipe, 404, 'Recipe not found.');

        return $recipe;
    }
}
