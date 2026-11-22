<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\BuildInstallPlan;
use App\Modules\GameRecipes\Support\LauncherLineup;
use App\Modules\GameRecipes\Support\LauncherSupport;
use App\Modules\GameRecipes\Support\PlanServerAddress;
use App\Modules\WebVoice\NativeClient\Support\NativeClientConfig;
use App\Modules\WebVoice\NativeClient\Support\SignedInstallPlanUrls;
use App\Support\SafeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Game recipes for the native client (bearer API, GameRecipes module). The catalogue and the plan are
 * the browser/agent surfaces' own: published recipes only, the launcher lineup order, and
 * {@see BuildInstallPlan} with signed file-host links instead of the agent's bearer route.
 * Docs: docs/infra/native-voice-client-api.md
 */
class RecipesApiController extends Controller
{
    /** The slug allowlist: lowercase words joined by hyphens, nothing else ever reaches a query. */
    public const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public function index(Request $request, LauncherLineup $lineup, LauncherSupport $support): JsonResponse
    {
        $recipes = $lineup->filter(GameRecipe::query()->published()->with('files')->orderBy('title')->get());

        return response()->json([
            'recipes' => $recipes->map(fn (GameRecipe $recipe): array => $this->summary($recipe, $support, $request))->values()->all(),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function show(Request $request, string $slug, LauncherSupport $support, PlanServerAddress $servers): JsonResponse
    {
        $recipe = $this->find($slug);
        // No client receives a raw `{{server_host}}`: see PlanServerAddress.
        $variables = $servers->variablesFor($recipe, $this->user($request));

        return response()->json([
            'recipe' => $this->summary($recipe, $support, $request) + [
                'description' => $recipe->description,
                'aliases' => $recipe->aliases,
                'homepage' => $recipe->homepage,
                'license' => $recipe->license,
                'portable' => $support->isPortable($recipe),
                'supports_multiplayer' => $recipe->supportsMultiplayer(),
                'launch_target' => PlanServerAddress::text($recipe->launchTarget(), $variables),
                'launch_targets' => array_map(static function (array $target): array {
                    $target['label'] = SafeText::line($target['label']) ?? '';
                    $target['notes'] = SafeText::line($target['notes']) ?: null;

                    return $target;
                }, PlanServerAddress::launchTargets($recipe->launchTargets(), $variables)),
            ],
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * The install plan for the caller's OS. A read (GET) that records who asked, like the agent route;
     * `dry_run=1` skips the audit row.
     */
    public function plan(Request $request, string $slug, BuildInstallPlan $builder, SignedInstallPlanUrls $urls): JsonResponse
    {
        $data = $request->validate([
            'os' => ['nullable', 'in:windows,linux,macos,win,mac'],
            'event_slug' => ['nullable', 'string', 'max:120'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $recipe = $this->find($slug);
        $event = isset($data['event_slug'])
            ? Event::query()->publiclyVisible()->where('slug', $data['event_slug'])->firstOrFail()
            : null;

        $plan = $builder->handle(
            $recipe,
            $this->user($request),
            (string) ($data['os'] ?? 'windows'),
            $event,
            record: ! $request->boolean('dry_run'),
            urls: $urls,
        );

        return response()->json(
            ['plan_version' => NativeClientConfig::PLAN_VERSION] + $plan,
            200,
            ['Cache-Control' => 'no-store, private'],
        );
    }

    /** A published recipe by its exact slug (aliases are an agent convenience, not a client one). */
    private function find(string $slug): GameRecipe
    {
        abort_unless(preg_match('/^'.self::SLUG_PATTERN.'$/', $slug) === 1, 404);

        return GameRecipe::query()->published()->where('slug', $slug)->with('files')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function summary(GameRecipe $recipe, LauncherSupport $support, Request $request): array
    {
        $metadata = is_array($recipe->metadata) ? $recipe->metadata : [];
        $genre = $metadata['genre'] ?? null;
        $players = $metadata['players'] ?? null;
        $playersMax = $metadata['players_max'] ?? null;

        return [
            'slug' => $recipe->slug,
            'title' => $recipe->title,
            'summary' => $recipe->summary,
            'version' => $recipe->version,
            'status' => $recipe->status->value,
            'platforms' => $recipe->platforms,
            'genre' => is_string($genre) && $genre !== '' ? $genre : null,
            'players' => is_scalar($players) && (string) $players !== '' ? (string) $players : null,
            'players_max' => is_numeric($playersMax) ? (int) $playersMax : null,
            // Declared size of the recipe's files, where the recipe pins it; null when none does.
            'size_bytes' => ($sum = (int) $recipe->files->sum('size_bytes')) > 0 ? $sum : null,
            'installable' => ($recipe->requirements ?? []) !== []
                && ($recipe->steps ?? []) !== []
                && $recipe->launchTarget() !== null,
            'launcher_supported' => $support->supports($recipe),
            'in_lineup' => $recipe->in_lineup,
            'play_url' => $recipe->playUrl($request->getHost()),
            'updated_at' => $recipe->updated_at?->toIso8601String(),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
