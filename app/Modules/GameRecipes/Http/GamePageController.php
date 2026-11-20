<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Support\RecipeFileMatcher;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\ServerListProjection;
use App\Modules\Lfg\Actions\CreateLfgPost;
use App\Modules\Lfg\Models\LfgPost;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\Tournament;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The participant-facing games library: one page per installable game with
 * install/launch actions and the live server list. This is the "open the URL
 * on the LAN and see the game" surface; the actual install runs through the
 * agent (deep link or copied command), never in the browser.
 *
 * Everything the page needs is loaded once per request (recipes + files,
 * approved LAN files, servers, tournaments, LFG posts) and matched against the
 * recipes in PHP: the heavy relations used to be queried per recipe, which made
 * the library page O(recipes) queries. `lan_ready` is computed here so the
 * client filter ("Nur LAN-Spiele") never has to guess from an empty server row.
 */
class GamePageController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function index(Request $request, Modules $modules, RecipeFileMatcher $matcher): Response
    {
        $this->authUser($request);
        $event = $this->activeEvent();
        $recipes = $this->publishedRecipes();
        $matches = $this->matchedFiles($recipes, $matcher);
        /** @var Collection<int, ServerLink> $servers */
        $servers = $modules->enabled(Module::GameServers) ? $this->serverLinks() : new Collection;
        /** @var Collection<int, Tournament> $tournaments */
        $tournaments = $event === null ? new Collection : $this->tournamentsForEvent($event);
        /** @var Collection<int, LfgPost> $lfg */
        $lfg = $event === null || ! $modules->enabled(Module::Lfg) ? new Collection : $this->lfgForEvent($event);

        $onlyLan = $request->boolean('only_lan');

        $games = $recipes
            ->map(function (GameRecipe $recipe) use ($matches, $servers, $tournaments, $lfg): array {
                return $this->card($recipe, $matches[$recipe->id] ?? [], $this->serversForRecipe($recipe, $servers), $tournaments, $lfg);
            })
            ->values();

        if ($onlyLan) {
            $games = $games
                ->filter(fn (array $game): bool => $game['lan_ready'] === true)
                ->values();
        }

        return Inertia::render('Games/Index', [
            'games' => $games->all(),
            'active_event' => $event === null ? null : ['slug' => $event->slug, 'name' => $event->name],
            'filters' => ['only_lan' => $onlyLan],
            'modules' => [
                'game_servers' => $modules->enabled(Module::GameServers),
                'web_voice' => $modules->enabled(Module::WebVoice),
                'lfg' => $modules->enabled(Module::Lfg),
            ],
        ]);
    }

    public function show(Request $request, string $slug, Modules $modules, RecipeFileMatcher $matcher): Response
    {
        $this->authUser($request);
        $event = $this->activeEvent();
        $recipes = $this->publishedRecipes();
        $needle = mb_strtolower($slug);
        $recipe = $recipes->first(fn (GameRecipe $candidate): bool => $candidate->slug === $needle
            || in_array($needle, $candidate->searchTerms(), true));

        abort_unless($recipe instanceof GameRecipe, 404);

        $allMatches = $this->matchedFiles($recipes, $matcher);
        $matches = $allMatches[$recipe->id] ?? [];
        /** @var Collection<int, ServerLink> $servers */
        $servers = $modules->enabled(Module::GameServers) ? $this->serverLinks() : new Collection;
        $recipeServers = $this->serversForRecipe($recipe, $servers);
        /** @var Collection<int, Tournament> $tournaments */
        $tournaments = $event === null ? new Collection : $this->tournamentsForEvent($event);
        /** @var Collection<int, LfgPost> $lfg */
        $lfg = $event === null || ! $modules->enabled(Module::Lfg) ? new Collection : $this->lfgForEvent($event);

        return Inertia::render('Games/Show', [
            'game' => $this->card($recipe, $matches, $recipeServers, $tournaments, $lfg) + [
                'description' => $recipe->description,
                'license' => $recipe->license,
                'requirements' => collect($this->requirementRows($recipe))->map(function (array $pair) use ($matches): array {
                    $requirement = $pair['requirement'];
                    $entry = $pair['entry'];
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
                'requires_agent' => $this->requiresAgent($recipe),
            ],
            'servers' => $recipeServers,
            'tournaments' => $this->tournamentsForRecipe($recipe, $tournaments),
            'lfg' => $this->lfgForRecipe($recipe, $lfg)->map(fn (LfgPost $post): array => [
                'id' => $post->id,
                'title' => $post->title,
                'body' => $post->body,
                'slots_needed' => $post->slots_needed,
                'author' => $post->user?->name,
                'expires_at' => $post->expires_at->toIso8601String(),
            ])->all(),
            'active_event' => $event === null ? null : ['slug' => $event->slug, 'name' => $event->name],
            'voice_url' => $modules->enabled(Module::WebVoice) ? route('webvoice.index') : null,
            'library' => $recipes
                ->map(fn (GameRecipe $candidate): array => $this->sidebarItem(
                    $candidate,
                    $allMatches[$candidate->id] ?? [],
                    $this->serversForRecipe($candidate, $servers),
                ))
                ->all(),
            'active' => $recipe->slug,
            'modules' => [
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

        $create->handle($event, $this->authUser($request), [
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
        // One query: the newest event whose lifecycle is active wins, otherwise
        // the newest publicly visible one. (The old version ran the second
        // query whenever no active event existed, on every page load.)
        $active = [EventStatus::Live->value, EventStatus::Registration->value, EventStatus::Announced->value];

        return Event::query()
            ->publiclyVisible()
            ->orderByRaw(
                'case when status in (?, ?, ?) then 1 else 0 end desc',
                $active,
            )
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return Collection<int, GameRecipe> */
    private function publishedRecipes(): Collection
    {
        return GameRecipe::query()
            ->published()
            ->with('files')
            ->orderBy('title')
            ->get();
    }

    /**
     * Match every recipe file against the approved LAN files with ONE query.
     * Semantics mirror {@see RecipeFileMatcher::match()} (pinned upload wins,
     * otherwise the largest/newest filename match), so neither the card nor
     * the detail view ever issues a query per recipe or per file.
     *
     * @param  Collection<int, GameRecipe>  $recipes
     * @return array<int, array<int, SharedFile>> keyed by recipe id, then recipe-file id
     */
    private function matchedFiles(Collection $recipes, RecipeFileMatcher $matcher): array
    {
        if ($recipes->isEmpty()) {
            return [];
        }

        /** @var Collection<int, SharedFile> $candidates */
        $candidates = $matcher->query(null)
            ->orderByDesc('size_bytes')
            ->orderByDesc('id')
            ->get()
            ->keyBy('id');

        $result = [];

        foreach ($recipes as $recipe) {
            foreach ($recipe->files as $entry) {
                $shared = null;

                if ($entry->shared_file_id !== null) {
                    $pinned = $candidates->get($entry->shared_file_id);
                    $shared = $pinned instanceof SharedFile ? $pinned : null;
                }

                if ($shared === null) {
                    $patterns = $entry->patterns();

                    if ($patterns !== []) {
                        $shared = $candidates->first(
                            fn (SharedFile $candidate): bool => $matcher->nameMatches($candidate->original_name, $patterns),
                        );
                    }
                }

                if ($shared instanceof SharedFile) {
                    $result[$recipe->id][$entry->id] = $shared;
                }
            }
        }

        return $result;
    }

    /** @return Collection<int, ServerLink> */
    private function serverLinks(): Collection
    {
        return ServerLink::query()
            ->with(['game', 'match.tournament.game', 'tournament.game', 'event'])
            ->where(fn ($query) => $query->where('permanent', true)->orWhereHas(
                'event',
                fn ($event) => $event->where('status', '!=', 'draft'),
            ))
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Tournament> */
    private function tournamentsForEvent(Event $event): Collection
    {
        return Tournament::query()
            ->where('event_id', $event->id)
            ->whereIn('status', [
                TournamentStatus::Announced->value,
                TournamentStatus::Enrollment->value,
                TournamentStatus::CheckIn->value,
                TournamentStatus::Live->value,
            ])
            ->with('game')
            ->orderBy('starts_at')
            ->get();
    }

    /** @return Collection<int, LfgPost> */
    private function lfgForEvent(Event $event): Collection
    {
        return LfgPost::query()
            ->where('event_id', $event->id)
            ->active()
            ->with('user:id,name')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  Collection<int, ServerLink>  $links
     * @return list<array<string, mixed>>
     */
    private function serversForRecipe(GameRecipe $recipe, Collection $links): array
    {
        $terms = $recipe->searchTerms();

        return array_values($links
            ->filter(function (ServerLink $link) use ($terms): bool {
                $game = $link->match?->tournament->game ?? $link->game;

                return $game !== null && $this->matchesTerms($terms, $game->name, $game->slug);
            })
            ->map(static function (ServerLink $link): array {
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
            })
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, Tournament>  $tournaments
     * @return list<array<string, mixed>>
     */
    private function tournamentsForRecipe(GameRecipe $recipe, Collection $tournaments): array
    {
        $terms = $recipe->searchTerms();

        return array_values($tournaments
            ->filter(fn (Tournament $tournament): bool => $tournament->game !== null
                && $this->matchesTerms($terms, $tournament->game->name, $tournament->game->slug))
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

    /**
     * @param  Collection<int, LfgPost>  $posts
     * @return Collection<int, LfgPost>
     */
    private function lfgForRecipe(GameRecipe $recipe, Collection $posts): Collection
    {
        $terms = $recipe->searchTerms();

        return $posts
            ->filter(function (LfgPost $post) use ($terms): bool {
                $game = mb_strtolower((string) $post->game);

                if ($game === '') {
                    return false;
                }

                foreach ($terms as $term) {
                    if ($term !== '' && (str_contains($game, $term) || str_contains($term, $game))) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    /**
     * Case-insensitive "same game" test shared by servers, tournaments and LFG:
     * a search term (slug, title, alias) must appear in the name/slug, or the
     * ground-truth slug must appear in the term. Empty names never match — the
     * old `str_contains($term, '')` path listed a game-less tournament on every
     * recipe.
     *
     * @param  list<string>  $terms
     */
    private function matchesTerms(array $terms, ?string $name, ?string $slug): bool
    {
        $name = mb_strtolower((string) $name);
        $slug = mb_strtolower((string) $slug);

        if ($name === '' && $slug === '') {
            return false;
        }

        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }

            if (str_contains($name, $term) || str_contains($slug, $term)) {
                return true;
            }

            if ($slug !== '' && str_contains($term, $slug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pair the authored YAML requirements with their persisted
     * {@see GameRecipeFile} rows. The two lists are written together by the
     * sync, but a manual recipe edit, a partial sync or a reordered `sort`
     * can diverge — mapping them by array index then pairs the "UT2004 DVD"
     * requirement with the avatar row and the file panel silently shows
     * "fehlt". Role + label is the stable key; a same-role row is the
     * fallback for a renamed label, and a requirement with no row at all
     * stays visible (as missing) instead of disappearing.
     *
     * @return list<array{requirement: array<string, mixed>, entry: GameRecipeFile|null}>
     */
    private function requirementRows(GameRecipe $recipe): array
    {
        $files = array_values($recipe->files->all());
        $used = [];
        $rows = [];

        foreach ($recipe->requirements ?? [] as $requirement) {
            $role = (string) ($requirement['role'] ?? 'content');
            $label = (string) ($requirement['label'] ?? '');
            $entry = null;

            foreach ($files as $index => $file) {
                if (! isset($used[$index]) && $file->role->value === $role && $file->label === $label) {
                    $entry = $file;
                    $used[$index] = true;

                    break;
                }
            }

            if (! $entry instanceof GameRecipeFile) {
                foreach ($files as $index => $file) {
                    if (! isset($used[$index]) && $file->role->value === $role) {
                        $entry = $file;
                        $used[$index] = true;

                        break;
                    }
                }
            }

            $rows[] = ['requirement' => $requirement, 'entry' => $entry];
        }

        return $rows;
    }

    /**
     * @param  array<int, SharedFile>  $matches
     * @param  list<array<string, mixed>>  $servers
     */
    private function lanReady(GameRecipe $recipe, array $matches, array $servers): bool
    {
        foreach ($recipe->files as $entry) {
            if ($entry->required && isset($matches[$entry->id])) {
                return true;
            }
        }

        return $servers !== [];
    }

    /** Whether `lanomat-agent install` has anything to install for this recipe. */
    private function installable(GameRecipe $recipe): bool
    {
        return $recipe->files->isNotEmpty() || ($recipe->requirements ?? []) !== [];
    }

    /** Whether the recipe needs the native client at all (install or local launch). */
    private function requiresAgent(GameRecipe $recipe): bool
    {
        return $this->installable($recipe) || $recipe->launchTarget() !== null;
    }

    /**
     * The optional avatar bridge (e.g. the VRM -> UT2004 converter). It is a
     * convenience on top of the LAN files, so only an absolute http(s) URL is
     * exposed — a recipe must never turn agent content into a `javascript:` or
     * `data:` link on the games page.
     */
    private function avatarConverterUrl(GameRecipe $recipe): ?string
    {
        $url = $recipe->metadata['avatar_converter_url'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(mb_strtolower($scheme), ['http', 'https'], true) ? $url : null;
    }

    /**
     * @param  array<int, SharedFile>  $matches
     * @param  list<array<string, mixed>>  $servers
     * @param  Collection<int, Tournament>  $tournaments
     * @param  Collection<int, LfgPost>  $lfg
     * @return array<string, mixed>
     */
    private function card(
        GameRecipe $recipe,
        array $matches,
        array $servers,
        Collection $tournaments,
        Collection $lfg,
    ): array {
        $running = $this->tournamentsForRecipe($recipe, $tournaments);

        return [
            'slug' => $recipe->slug,
            'title' => $recipe->title,
            'aliases' => $recipe->aliases,
            'version' => $recipe->version,
            'summary' => $recipe->summary,
            'platforms' => $recipe->platforms,
            'status' => $recipe->status->value,
            'homepage' => $recipe->homepage,
            'avatar_converter_url' => $this->avatarConverterUrl($recipe),
            'installable' => $this->installable($recipe),
            'install_command' => $recipe->installCommand(),
            'launch_command' => $recipe->launchCommand(),
            'install_deeplink' => $recipe->installDeepLink(),
            'launch_deeplink' => $recipe->launchDeepLink(),
            'play_url' => $recipe->playUrl(),
            'launch_target' => $recipe->launchTarget(),
            'supports_multiplayer' => $recipe->supportsMultiplayer(),
            'lan_ready' => $this->lanReady($recipe, $matches, $servers),
            'server_count' => count($servers),
            'running_tournaments' => $running,
            'running_tournaments_count' => count($running),
            'open_lfg' => $this->lfgForRecipe($recipe, $lfg)->count(),
            'install_button' => 'Installieren',
            'launch_button' => 'Starten',
        ];
    }

    /**
     * @param  array<int, SharedFile>  $matches
     * @param  list<array<string, mixed>>  $servers
     * @return array<string, mixed>
     */
    private function sidebarItem(GameRecipe $recipe, array $matches, array $servers): array
    {
        return [
            'slug' => $recipe->slug,
            'title' => $recipe->title,
            'platforms' => $recipe->platforms,
            'summary' => $recipe->summary,
            'lan_ready' => $this->lanReady($recipe, $matches, $servers),
        ];
    }
}
