<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Http\Api;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameRecipes\Actions\ContributeRecipe;
use App\Modules\GameRecipes\Exceptions\RecipeException;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Support\AvatarCatalog;
use App\Modules\GameRecipes\Support\BuildInstallPlan;
use App\Modules\GameRecipes\Support\LauncherLineup;
use App\Modules\GameRecipes\Support\LauncherSupport;
use App\Modules\GameRecipes\Support\PlanServerAddress;
use App\Modules\GameRecipes\Support\RecipeFileMatcher;
use App\Modules\Mcp\Support\ContributionCatalog;
use App\Modules\Mcp\Support\SafeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** The agent's REST surface for recipes: list, read, skill, plan, files, avatars, contribute. */
class RecipeController extends Controller
{
    use ResolvesAuthenticatedUser;

    /**
     * The metadata keys a contribution may set through the REST surface. The
     * rest of a recipe's metadata document belongs to the orga and to the
     * recipe source; `launcher_supported` in particular is an orga override.
     *
     * @var list<string>
     */
    private const CONTRIBUTION_METADATA_KEYS = [
        'portable',
        'genre',
        'players',
        'players_max',
        'lan_recommended',
        'tags',
        'multiplayer',
    ];

    public function index(Request $request, LauncherLineup $lineup, LauncherSupport $support): JsonResponse
    {
        $user = $this->authUser($request);
        $query = mb_strtolower($request->string('query')->toString());

        $recipes = $lineup->filter(GameRecipe::query()
            ->visibleTo($user)
            ->orderBy('title')
            ->get())
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
                // A browser recipe has no install path, but the launcher can
                // still open its `play_url`; the shell uses that as "Spielen".
                'launcher_supported' => $support->supports($recipe),
                'in_lineup' => $recipe->in_lineup,
                // A draft is only visible to its contributor (or the orga), so
                // its pull request travels with the row they see.
                'pull_request' => $this->pullRequestFor($recipe, $user),
                'play_url' => $recipe->playUrl($request->getHost()),
                'updated_at' => $recipe->updated_at?->toIso8601String(),
            ])->all(),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function show(Request $request, string $slug, RecipeFileMatcher $matcher, LauncherSupport $support, PlanServerAddress $servers): JsonResponse
    {
        $user = $this->authUser($request);
        $recipe = $this->findRecipe($request, $slug);
        $matches = $matcher->matchesFor($recipe, $user);
        // An agent reads its launch targets from here, so the plan variables are
        // filled here as well: no client ever receives a raw `{{server_host}}`.
        $variables = $servers->variablesFor($recipe, $user);

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
                'launcher_supported' => $support->supports($recipe),
                'portable' => $support->isPortable($recipe),
                'pull_request' => $this->pullRequestFor($recipe, $user),
                'launch_target' => PlanServerAddress::text($recipe->launchTarget(), $variables),
                'launch_targets' => PlanServerAddress::launchTargets($this->launchTargets($recipe), $variables),
                'supports_multiplayer' => $recipe->supportsMultiplayer(),
                'install_command' => $recipe->installCommand(),
                'launch_command' => $recipe->launchCommand(),
                'requirements' => $recipe->requirements,
                'steps' => array_map(static fn (array $step): array => PlanServerAddress::step($step, $variables), array_values(array_filter((array) ($recipe->steps ?? []), 'is_array'))),
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
        // `query` is the current name; `q` stays accepted for old clients.
        $query = $request->input('query');
        $legacy = $request->input('q');
        $search = is_string($query) ? $query : (is_string($legacy) ? $legacy : '');

        return response()->json([
            'data' => $catalog->forRecipe($recipe, $this->authUser($request), $search),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function contribute(Request $request, ContributeRecipe $contribute, LauncherSupport $support): JsonResponse
    {
        $user = $this->authUser($request);

        // Anyone may propose a draft; the policy decides, the same as the tool.
        Gate::forUser($user)->authorize('create', GameRecipe::class);

        $data = $request->validate([
            'slug' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:10000'],
            'version' => ['nullable', 'string', 'max:40'],
            'homepage' => ['nullable', 'url:http,https', 'max:500'],
            'license' => ['nullable', 'string', 'max:200'],
            'play_url' => ['nullable', 'url:http,https', 'max:500'],
            'platforms' => ['nullable', 'array', 'max:3'],
            'platforms.*' => ['string', 'in:windows,linux,macos'],
            'aliases' => ['nullable', 'array', 'max:20'],
            'aliases.*' => ['string', 'max:64'],
            'requires' => ['nullable', 'array', 'max:50'],
            'requires.*' => ['array'],
            'requires.*.shared_file_id' => ['nullable', 'integer'],
            'requires.*.role' => ['nullable', 'string', 'max:40'],
            'requires.*.label' => ['nullable', 'string', 'max:200'],
            'requires.*.match' => ['nullable', 'array', 'max:20'],
            'requires.*.match.*' => ['string', 'max:200'],
            'requires.*.required' => ['nullable', 'boolean'],
            // The validator treats a manual requirement as satisfied without
            // a matching file; dropping the flag would turn a valid payload
            // into a validation error.
            'requires.*.manual' => ['nullable', 'boolean'],
            'requires.*.os' => ['nullable', 'string', 'in:windows,linux,macos,any'],
            'requires.*.notes' => ['nullable', 'string', 'max:1000'],
            'requires.*.author' => ['nullable', 'string', 'max:120'],
            'requires.*.license' => ['nullable', 'string', 'max:200'],
            'requires.*.tags' => ['nullable', 'array', 'max:20'],
            'requires.*.tags.*' => ['string', 'max:40'],
            'requires.*.size_bytes' => ['nullable', 'integer', 'min:0'],
            'requires.*.sha256' => ['nullable', 'string', 'max:64'],
            'requires.*.url' => ['nullable', 'url:http,https', 'max:1000'],
            'requirements' => ['nullable', 'array', 'max:50'],
            'requirements.*' => ['array'],
            'requirements.*.shared_file_id' => ['nullable', 'integer'],
            'requirements.*.role' => ['nullable', 'string', 'max:40'],
            'requirements.*.label' => ['nullable', 'string', 'max:200'],
            'requirements.*.match' => ['nullable', 'array', 'max:20'],
            'requirements.*.match.*' => ['string', 'max:200'],
            'requirements.*.required' => ['nullable', 'boolean'],
            'requirements.*.manual' => ['nullable', 'boolean'],
            'requirements.*.os' => ['nullable', 'string', 'in:windows,linux,macos,any'],
            'requirements.*.notes' => ['nullable', 'string', 'max:1000'],
            'requirements.*.author' => ['nullable', 'string', 'max:120'],
            'requirements.*.license' => ['nullable', 'string', 'max:200'],
            'requirements.*.tags' => ['nullable', 'array', 'max:20'],
            'requirements.*.tags.*' => ['string', 'max:40'],
            'requirements.*.size_bytes' => ['nullable', 'integer', 'min:0'],
            'requirements.*.sha256' => ['nullable', 'string', 'max:64'],
            'requirements.*.url' => ['nullable', 'url:http,https', 'max:1000'],
            'launch_targets' => ['nullable', 'array', 'max:10'],
            'launch_targets.*' => ['array'],
            'launch_targets.*.id' => ['nullable', 'string', 'max:64'],
            'launch_targets.*.label' => ['nullable', 'string', 'max:160'],
            'launch_targets.*.os' => ['nullable', 'string', 'in:windows,linux,macos,any'],
            'launch_targets.*.command' => ['required', 'string', 'max:2000'],
            'launch_targets.*.shell' => ['nullable', 'string', 'in:powershell,cmd,bash'],
            'launch_targets.*.notes' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
            'metadata.portable' => ['nullable', 'boolean'],
            'metadata.genre' => ['nullable', 'string', 'max:80'],
            'metadata.players' => ['nullable', 'string', 'max:20'],
            'metadata.players_max' => ['nullable', 'integer', 'min:1', 'max:1024'],
            'metadata.lan_recommended' => ['nullable', 'boolean'],
            'metadata.tags' => ['nullable', 'array', 'max:20'],
            'metadata.tags.*' => ['string', 'max:40'],
            'metadata.multiplayer' => ['nullable', 'boolean'],
            'metadata.launcher_supported' => ['nullable', 'boolean'],
            'steps' => ['nullable', 'array', 'max:50'],
            'skill_description' => ['nullable', 'string', 'max:1024'],
            'skill_markdown' => ['nullable', 'string', 'max:65535', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
        ], [
            // ContributeRecipe's own validator only caps the length; a control
            // character would travel into a public SKILL.md.
            'skill_markdown.not_regex' => 'The skill_markdown may only contain printable characters, newlines, carriage returns and tabs.',
        ]);

        // Only the keys a contribution may set reach the action; the rest of a
        // metadata document (notably the orga's `launcher_supported` switch)
        // stays what the recipe already carries.
        if (is_array($data['metadata'] ?? null)) {
            // The launcher switch belongs to the orga; from anyone else it is dropped here
            // (and ignored again by the action).
            $allowed = $user->isOrga()
                ? [...self::CONTRIBUTION_METADATA_KEYS, 'launcher_supported']
                : self::CONTRIBUTION_METADATA_KEYS;
            $metadata = array_intersect_key($data['metadata'], array_flip($allowed));

            if ($metadata !== []) {
                $data['metadata'] = $metadata;
            } else {
                unset($data['metadata']);
            }
        }

        $data['via'] = 'api';

        try {
            $recipe = $contribute->handle($user, $data);
        } catch (RecipeException $exception) {
            // The schema check of the assembled recipe (a step without an id,
            // an unknown platform, …): every problem at once, as a validation
            // answer the caller can act on instead of a server error.
            throw ValidationException::withMessages(['recipe' => $exception->problems]);
        }

        return response()->json([
            'recipe' => [
                'slug' => $recipe->slug,
                'title' => $recipe->title,
                'status' => $recipe->status->value,
                'launcher_supported' => $support->supports($recipe),
                'pull_request' => $recipe->pullRequest(),
            ],
            'catalog' => app(ContributionCatalog::class)->for($recipe),
        ], 201, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * The pull request of a recipe, for its contributor and for the orga. A
     * participant never learns whether somebody else's draft was proposed.
     *
     * @return array{number: int|null, url: string|null, state: string, label: string}|null
     */
    private function pullRequestFor(GameRecipe $recipe, User $user): ?array
    {
        if (! $user->isOrga() && $recipe->contributor_user_id !== $user->id) {
            return null;
        }

        return $recipe->pullRequest();
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
