<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\CurrentEvent;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Support\RecipeFileMatcher;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\ServerListProjection;
use App\Modules\Lfg\Actions\CreateLfgPost;
use App\Modules\Lfg\Models\LfgPost;
use App\Modules\Tournaments\Models\Tournament;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The participant-facing games library: one page per installable game with
 * install/launch actions and the live server list. This is the "open the URL
 * on the LAN and see the game" surface; the actual install runs through the
 * agent (deep link or copied command), never in the browser.
 */
class GamePageController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function index(Request $request, Modules $modules, RecipeFileMatcher $matcher): Response
    {
        $recipes = GameRecipe::query()->published()->orderBy('title')->get();
        $event = $this->activeEvent();

        return Inertia::render('Games/Index', [
            'games' => $recipes->map(fn (GameRecipe $recipe): array => $this->card($recipe) + [
                'lan_ready' => $this->lanReady($recipe, $this->authUser($request), $matcher),
                'running_tournaments' => $modules->enabled(Module::Tournaments) && $event !== null
                    ? $this->tournamentsFor($recipe, $event)
                    : [],
                'open_lfg' => $modules->enabled(Module::Lfg) && $event !== null
                    ? $this->lfgFor($recipe, $event)->count()
                    : 0,
            ])->all(),
            'active_event' => $event === null ? null : ['slug' => $event->slug, 'name' => $event->name],
            // Renamed from `modules`: the shell already shares a `modules` list
            // (enabled module keys), and an object with the same prop shadows it
            // in the Vue pages (AppSidebar then calls .includes on an object).
            'game_modules' => [
                'game_servers' => $modules->enabled(Module::GameServers),
                'web_voice' => $modules->enabled(Module::WebVoice),
                'lfg' => $modules->enabled(Module::Lfg),
            ],
        ]);
    }

    public function show(Request $request, string $slug, Modules $modules, RecipeFileMatcher $matcher): Response
    {
        $needle = mb_strtolower($slug);
        $recipe = GameRecipe::query()
            ->published()
            ->get()
            ->first(fn (GameRecipe $candidate): bool => $candidate->slug === $needle
                || in_array($needle, $candidate->searchTerms(), true));

        abort_unless($recipe instanceof GameRecipe, 404);

        $user = $this->authUser($request);
        $matches = $matcher->matchesFor($recipe, $user);
        $servers = $modules->enabled(Module::GameServers) ? $this->serversFor($recipe) : [];
        $playUrl = $recipe->playUrl($request->getHost());
        $event = $this->activeEvent();
        $tournaments = $modules->enabled(Module::Tournaments) && $event !== null
            ? $this->tournamentsFor($recipe, $event)
            : [];
        $lfg = $modules->enabled(Module::Lfg) && $event !== null
            ? $this->lfgFor($recipe, $event)
            : collect();

        return Inertia::render('Games/Show', [
            'game' => $this->card($recipe) + [
                'description' => $recipe->description,
                'homepage' => $recipe->homepage,
                'license' => $recipe->license,
                'requirements' => collect($recipe->requirements ?? [])->map(function (array $requirement, int $index) use ($recipe, $matches): array {
                    $entry = $recipe->files->values()->get($index);
                    $shared = $entry instanceof GameRecipeFile ? ($matches[$entry->id] ?? null) : null;

                    return [
                        'role' => (string) ($requirement['role'] ?? 'content'),
                        'label' => (string) ($requirement['label'] ?? 'Datei'),
                        'required' => (bool) ($requirement['required'] ?? false),
                        'match' => array_values(array_map('strval', (array) ($requirement['match'] ?? []))),
                        'available_on_lan' => $shared !== null,
                        'name' => $shared?->original_name,
                        'size_bytes' => $shared?->size_bytes,
                    ];
                })->all(),
                'steps' => collect($recipe->steps ?? [])->map(fn (array $step): array => [
                    'id' => (string) ($step['id'] ?? ''),
                    'title' => (string) ($step['title'] ?? ''),
                    'os' => (string) ($step['os'] ?? 'any'),
                    'optional' => (bool) ($step['optional'] ?? false),
                    'detail' => (string) ($step['detail'] ?? ''),
                ])->all(),
                'avatars' => $recipe->files->filter(fn (GameRecipeFile $file): bool => $file->isAvatar())->count(),
                'play_url' => $playUrl,
                'launch_target' => $recipe->launchTarget(),
                'supports_multiplayer' => $recipe->supportsMultiplayer(),
                'lan_ready' => $this->lanReady($recipe, $user, $matcher),
                'requires_agent' => true,
            ],
            'servers' => $servers,
            'tournaments' => $tournaments,
            'lfg' => $lfg->map(fn (LfgPost $post): array => [
                'id' => $post->id,
                'title' => $post->title,
                'body' => $post->body,
                'slots_needed' => $post->slots_needed,
                'author' => $post->user?->name,
                'expires_at' => $post->expires_at->toIso8601String(),
            ])->all(),
            'active_event' => $event === null ? null : ['slug' => $event->slug, 'name' => $event->name],
            'voice_url' => $modules->enabled(Module::WebVoice) ? route('webvoice.index') : null,
            'library' => GameRecipe::query()->published()->orderBy('title')->get()
                ->map(fn (GameRecipe $candidate): array => $this->sidebarItem($candidate, $this->lanReady($candidate, $user, $matcher)))
                ->all(),
            'active' => $recipe->slug,
            // Renamed from `modules`: the shell already shares a `modules` list
            // (enabled module keys), and an object with the same prop shadows it
            // in the Vue pages (AppSidebar then calls .includes on an object).
            'game_modules' => [
                'game_servers' => $modules->enabled(Module::GameServers),
                'web_voice' => $modules->enabled(Module::WebVoice),
                'lfg' => $modules->enabled(Module::Lfg),
            ],
        ]);
    }

    /** One click: announce a round for this game in the active event (and let people join the voice chat). */
    public function createLfg(Request $request, string $slug, CreateLfgPost $create): RedirectResponse
    {
        $recipe = GameRecipe::query()->published()->where('slug', $slug)->firstOrFail();
        $event = $this->activeEvent();

        abort_if($event === null, 422, 'Kein aktives Event.');

        $user = $this->authUser($request);
        abort_if(Gate::forUser($user)->denies('create', LfgPost::class), 403);

        $create->handle($event, $user, [
            'title' => 'Runde '.$recipe->title,
            'game' => $recipe->title,
            'body' => 'Schnell zusammen spielen — '.$recipe->title.'.',
            'slots_needed' => max(1, (int) (($recipe->metadata['players_max'] ?? 5))),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Runde angekündigt. Schau in den Sprachchat.']);

        return back();
    }

    private function activeEvent(): ?Event
    {
        // Same precedence as the shell: a live event beats a future
        // registration/announced one (CurrentEvent is the single source).
        return app(CurrentEvent::class)->get()
            ?? Event::query()->publiclyVisible()->orderByDesc('starts_at')->orderByDesc('id')->first();
    }

    /** @return list<array<string, mixed>> */
    private function tournamentsFor(GameRecipe $recipe, Event $event): array
    {
        $terms = array_merge($recipe->searchTerms(), [mb_strtolower($recipe->slug)]);

        return array_values(Tournament::query()
            ->where('event_id', $event->id)
            ->whereIn('status', ['announced', 'enrollment', 'check_in', 'live'])
            ->with('game')
            ->orderBy('starts_at')
            ->get()
            ->filter(function (Tournament $tournament) use ($terms): bool {
                $name = mb_strtolower($tournament->game === null ? '' : $tournament->game->name);
                $gameSlug = mb_strtolower($tournament->game === null ? '' : $tournament->game->slug);

                foreach ($terms as $term) {
                    if ($term !== '' && (str_contains($name, $term) || str_contains($gameSlug, $term) || str_contains($term, $gameSlug))) {
                        return true;
                    }
                }

                return false;
            })
            ->map(fn (Tournament $tournament): array => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'status' => $tournament->status->value,
                'game' => $tournament->game?->name,
                'starts_at' => $tournament->starts_at->toIso8601String(),
                'url' => route('tournaments.show', ['tournament' => $tournament->id]),
            ])
            ->values()
            ->all());
    }

    /** @return Collection<int, LfgPost> */
    private function lfgFor(GameRecipe $recipe, Event $event)
    {
        $terms = array_merge($recipe->searchTerms(), [mb_strtolower($recipe->slug)]);

        return LfgPost::query()
            ->where('event_id', $event->id)
            ->active()
            ->with('user:id,name')
            ->orderByDesc('id')
            ->get()
            ->filter(function (LfgPost $post) use ($terms): bool {
                $game = mb_strtolower((string) $post->game);

                foreach ($terms as $term) {
                    if ($term !== '' && (str_contains($game, $term) || str_contains($term, $game))) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    private function lanReady(GameRecipe $recipe, User $user, RecipeFileMatcher $matcher): bool
    {
        $matches = $matcher->matchesFor($recipe, $user);

        foreach ($recipe->files as $entry) {
            if ($entry->required && isset($matches[$entry->id])) {
                return true;
            }
        }

        return $this->serversFor($recipe) !== [];
    }

    /** @return list<array<string, mixed>> */
    private function serversFor(GameRecipe $recipe): array
    {
        $names = array_merge($recipe->searchTerms(), [mb_strtolower($recipe->slug)]);

        $links = ServerLink::query()
            ->with(['game', 'match.tournament.game', 'event'])
            ->where(fn ($query) => $query->where('permanent', true)->orWhereHas(
                'event',
                fn ($event) => $event->where('status', '!=', 'draft'),
            ))
            ->get()
            ->filter(function (ServerLink $link) use ($names): bool {
                $gameName = mb_strtolower($link->game === null ? '' : $link->game->name);
                $gameSlug = mb_strtolower($link->game === null ? '' : $link->game->slug);

                foreach ($names as $name) {
                    if ($name !== '' && (str_contains($gameName, $name) || str_contains($gameSlug, $name) || str_contains($name, $gameSlug))) {
                        return true;
                    }
                }

                return false;
            });

        return array_values($links->map(static function (ServerLink $link): array {
            $dto = ServerListProjection::dto($link);

            return [
                'id' => $dto['id'],
                'title' => $dto['title'],
                'game' => $dto['game'],
                'matchLabel' => $dto['matchLabel'],
                'address' => $dto['address'],
                'port' => $dto['port'],
                'connectString' => $dto['connectString'],
                'connectIsLink' => $dto['connectIsLink'],
                'status' => $dto['status'],
                'query' => $dto['query'],
                'event' => $link->event?->slug,
            ];
        })->all());
    }

    /** @return array<string, mixed> */
    private function card(GameRecipe $recipe): array
    {
        return [
            'slug' => $recipe->slug,
            'title' => $recipe->title,
            'aliases' => $recipe->aliases,
            'version' => $recipe->version,
            'summary' => $recipe->summary,
            'platforms' => $recipe->platforms,
            'status' => $recipe->status->value,
            'homepage' => $recipe->homepage,
            'install_command' => $recipe->installCommand(),
            'launch_command' => $recipe->launchCommand(),
            'install_deeplink' => $recipe->installDeepLink(),
            'launch_deeplink' => $recipe->launchDeepLink(),
            'play_url' => $recipe->playUrl(),
            'launch_target' => $recipe->launchTarget(),
            'supports_multiplayer' => $recipe->supportsMultiplayer(),
            'install_button' => 'Installieren',
            'launch_button' => 'Starten',
        ];
    }

    /** @return array<string, mixed> */
    private function sidebarItem(GameRecipe $recipe, bool $lanReady): array
    {
        return [
            'slug' => $recipe->slug,
            'title' => $recipe->title,
            'platforms' => $recipe->platforms,
            'summary' => $recipe->summary,
            'lan_ready' => $lanReady,
        ];
    }
}
