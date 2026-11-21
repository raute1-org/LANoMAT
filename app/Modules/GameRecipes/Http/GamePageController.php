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
use App\Modules\GameRecipes\Support\LauncherBuilds;
use App\Modules\GameRecipes\Support\LauncherLineup;
use App\Modules\GameRecipes\Support\LauncherSupport;
use App\Modules\GameRecipes\Support\RecipeFileMatcher;
use App\Modules\GameRecipes\Support\RecipeGameMatcher;
use App\Modules\GameServers\Contracts\GameInstallLinks;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\ServerListProjection;
use App\Modules\Lfg\Actions\CreateLfgPost;
use App\Modules\Lfg\Models\LfgPost;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\Tournament;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use App\Support\SafeText;
use Carbon\CarbonInterface;
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

    public function __construct(private readonly RecipeGameMatcher $gameMatcher) {}

    public function index(Request $request, Modules $modules, RecipeFileMatcher $matcher, LauncherBuilds $launcher): Response
    {
        $this->authUser($request);
        $event = $this->activeEvent();
        $recipes = $this->publishedRecipes();
        $matches = $this->matchedFiles($recipes, $matcher);
        // One manifest read per request; false when nothing is published, so the
        // card never offers a download the /launcher page cannot deliver.
        $launcherAvailable = $launcher->builds() !== [];
        /** @var Collection<int, ServerLink> $servers */
        $servers = $modules->enabled(Module::GameServers) ? $this->serverLinks() : new Collection;
        // One install-link resolver per request: every card's server rows ask
        // the same published-recipe question, so this stays one catalogue query.
        $installLinks = ServerListProjection::installLinks();
        /** @var Collection<int, Tournament> $tournaments */
        $tournaments = $event === null ? new Collection : $this->tournamentsForEvent($event);
        /** @var Collection<int, LfgPost> $lfg */
        $lfg = $event === null || ! $modules->enabled(Module::Lfg) ? new Collection : $this->lfgForEvent($event);

        $onlyLan = $request->boolean('only_lan');
        // Real server activity, computed from the servers already loaded above
        // (no extra query): the default order is "what ran last", so the
        // library opens on the games people were just playing instead of the
        // alphabet. `lastActivityByRecipe()` also feeds the compact
        // "Zuletzt gespielt" cards at the top of the page.
        $activity = $this->lastActivityByRecipe($recipes, $servers, $installLinks);

        // The sort runs on the wrapper so the timestamp never reaches the page;
        // the bundled `game` array stays exactly the card shape.
        $games = $recipes
            ->map(function (GameRecipe $recipe) use ($matches, $servers, $tournaments, $lfg, $launcherAvailable, $activity, $installLinks): array {
                $played = $activity[$recipe->id] ?? null;

                return [
                    'played_ts' => $played['ts'] ?? null,
                    'game' => $this->card(
                        $recipe,
                        $matches[$recipe->id] ?? [],
                        $this->serversForRecipe($recipe, $servers, $installLinks),
                        $tournaments,
                        $lfg,
                        $launcherAvailable,
                    ) + [
                        'last_played_at' => $played['at'] ?? null,
                        'last_played_server' => $played['server'] ?? null,
                    ],
                ];
            })
            ->sort(static function (array $a, array $b): int {
                $aTs = $a['played_ts'];
                $bTs = $b['played_ts'];

                if ($aTs !== $bTs) {
                    // Never-played games sort after every played one.
                    if ($aTs === null) {
                        return 1;
                    }

                    if ($bTs === null) {
                        return -1;
                    }

                    return $bTs <=> $aTs;
                }

                return strcasecmp((string) $a['game']['title'], (string) $b['game']['title']);
            })
            ->map(static fn (array $row): array => $row['game'])
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

    public function show(Request $request, string $slug, Modules $modules, RecipeFileMatcher $matcher, LauncherBuilds $launcher): Response
    {
        $this->authUser($request);
        $event = $this->activeEvent();
        $recipes = $this->publishedRecipes();
        $launcherAvailable = $launcher->builds() !== [];
        $needle = mb_strtolower($slug);
        $recipe = $recipes->first(fn (GameRecipe $candidate): bool => $candidate->slug === $needle
            || in_array($needle, $candidate->searchTerms(), true));

        abort_unless($recipe instanceof GameRecipe, 404);

        $allMatches = $this->matchedFiles($recipes, $matcher);
        $matches = $allMatches[$recipe->id] ?? [];
        /** @var Collection<int, ServerLink> $servers */
        $servers = $modules->enabled(Module::GameServers) ? $this->serverLinks() : new Collection;
        $installLinks = ServerListProjection::installLinks();
        $recipeServers = $this->serversForRecipe($recipe, $servers, $installLinks);
        /** @var Collection<int, Tournament> $tournaments */
        $tournaments = $event === null ? new Collection : $this->tournamentsForEvent($event);
        /** @var Collection<int, LfgPost> $lfg */
        $lfg = $event === null || ! $modules->enabled(Module::Lfg) ? new Collection : $this->lfgForEvent($event);

        return Inertia::render('Games/Show', [
            'game' => $this->card($recipe, $matches, $recipeServers, $tournaments, $lfg, $launcherAvailable) + [
                'description' => $recipe->description,
                'license' => $recipe->license,
                'requirements' => collect($this->requirementRows($recipe))->map(function (array $pair, int $index) use ($matches): array {
                    $requirement = $pair['requirement'];
                    $entry = $pair['entry'];
                    $shared = $entry instanceof GameRecipeFile ? ($matches[$entry->id] ?? null) : null;
                    $role = (string) ($requirement['role'] ?? 'content');

                    return [
                        // Labels are not unique (a patch and a mod can both be
                        // called "Patch"), so the Vue list keys on role+index.
                        'key' => $role.'-'.$index,
                        'role' => $role,
                        'label' => (string) ($requirement['label'] ?? 'Datei'),
                        'required' => (bool) ($requirement['required'] ?? false),
                        'match' => array_values(array_map('strval', (array) ($requirement['match'] ?? []))),
                        'available_on_lan' => $shared !== null,
                        'name' => SafeText::fileName($shared?->original_name),
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
                    $this->serversForRecipe($candidate, $servers, $installLinks),
                ))
                ->all(),
            'active' => $recipe->slug,
            'game_modules' => [
                'game_servers' => $modules->enabled(Module::GameServers),
                'web_voice' => $modules->enabled(Module::WebVoice),
                'lfg' => $modules->enabled(Module::Lfg),
            ],
        ]);
    }

    /** One click: announce a round for this game in the active event (and let people join the voice chat). */
    public function createLfg(Request $request, string $slug, CreateLfgPost $create, Modules $modules): RedirectResponse
    {
        abort_unless($modules->enabled(Module::Lfg), 404);

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
        // The database keeps every published recipe; the player-facing library
        // only shows the curated first lineup. An empty lineup is the
        // back-compat path and keeps the whole catalogue visible.
        return app(LauncherLineup::class)->filter(
            GameRecipe::query()
                ->published()
                ->with('files')
                ->orderBy('title')
                ->get(),
        );
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
        $notDraft = fn ($query) => $query->where('status', '!=', EventStatus::Draft->value);

        return ServerLink::query()
            ->with(['game', 'match.tournament.game', 'tournament.game', 'event'])
            // Permanent servers, plus everything prepared for a published
            // event: an event-level preset server, a tournament server, a
            // match server. A tournament/match server has no `event_id` of its
            // own, and it is exactly what "recently played" is made of — the
            // games page used to skip it. A draft event's servers say nothing
            // about the LAN and stay out.
            ->where(fn ($query) => $query->where('permanent', true)
                ->orWhereHas('event', $notDraft)
                ->orWhereHas('tournament', fn ($tournament) => $tournament->whereHas('event', $notDraft))
                ->orWhereHas('match', fn ($match) => $match->whereHas(
                    'tournament',
                    fn ($tournament) => $tournament->whereHas('event', $notDraft),
                )))
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
     * The servers of one recipe, already projected to the wire shape.
     *
     * `joinOptions` and `install` come straight from the server projection:
     * the game page's join block needs the same per-game deep links and the
     * one-click launcher join the server browser offers. A browser recipe's
     * fallback row has neither (it is joined through the HTTPS `play_url`).
     *
     * @param  Collection<int, ServerLink>  $links
     * @return list<array<string, mixed>>
     */
    private function serversForRecipe(GameRecipe $recipe, Collection $links, GameInstallLinks $installLinks): array
    {
        /** @var list<array<string, mixed>> $servers */
        $servers = $this->matchedLinks($recipe, $links)
            ->map(static function (ServerLink $link) use ($installLinks): array {
                $dto = ServerListProjection::dto($link, install: $installLinks);

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
                    'joinOptions' => $dto['joinOptions'],
                    'install' => $dto['install'],
                    'event' => $link->event?->slug,
                ];
            })
            ->values()
            ->all();

        // A browser recipe has no ServerLink row: its one public server is
        // described by the recipe itself and joined through the HTTPS
        // `play_url`. An actual manual/permanent row for the same game wins,
        // so the page never shows the same server twice.
        if ($servers === []) {
            $browser = $recipe->browserServer(request()->getHost());

            if ($browser !== null) {
                $servers[] = $browser;
            }
        }

        return $servers;
    }

    /**
     * The servers that belong to a recipe (the matcher both the server list and
     * this page use).
     *
     * @param  Collection<int, ServerLink>  $links
     * @return Collection<int, ServerLink>
     */
    private function matchedLinks(GameRecipe $recipe, Collection $links): Collection
    {
        return $links
            ->filter(function (ServerLink $link) use ($recipe): bool {
                $game = $link->match?->tournament->game ?? $link->game;

                return $game !== null && $this->gameMatcher->matches($recipe, $game->slug, $game->name);
            })
            ->values();
    }

    /**
     * The last real server activity per recipe — the source of both the
     * library's "recently played" order and its compact cards.
     *
     * Runs over the {@see serverLinks()} collection that is loaded anyway
     * (which already drops servers of draft events), so it adds no query per
     * recipe. A link counts as activity when the server is up (Ready, still
     * starting, or a match in warmup/live) or answered its query within the
     * last day: a server that has been stopped and silent for weeks is not
     * "played", one that answered five minutes ago is.
     *
     * @param  Collection<int, GameRecipe>  $recipes
     * @param  Collection<int, ServerLink>  $links
     * @return array<int, array{ts: int, at: string, server: array<string, mixed>|null}> keyed by recipe id
     */
    private function lastActivityByRecipe(Collection $recipes, Collection $links, GameInstallLinks $installLinks): array
    {
        $candidates = $links
            ->filter(fn (ServerLink $link): bool => $this->activityAt($link) !== null)
            ->values();

        if ($candidates->isEmpty()) {
            return [];
        }

        $result = [];

        foreach ($recipes as $recipe) {
            $playedAt = null;
            $live = null;

            foreach ($this->matchedLinks($recipe, $candidates) as $link) {
                $at = $this->activityAt($link);

                if ($at === null) {
                    continue;
                }

                if ($playedAt === null || $at->gt($playedAt)) {
                    $playedAt = $at;
                }

                // The newest timestamp may belong to a server that just went
                // silent, so the live readout looks at the whole group.
                $live ??= $this->liveServer($link, $installLinks);
            }

            if ($playedAt === null) {
                continue;
            }

            $result[$recipe->id] = [
                'ts' => $playedAt->getTimestamp(),
                'at' => $playedAt->toIso8601String(),
                'server' => $live,
            ];
        }

        return $result;
    }

    /** When a server last looked alive for its game, or null when it never did. */
    private function activityAt(ServerLink $link): ?CarbonInterface
    {
        // A server prepared for an unpublished event says nothing about the LAN.
        if ($link->event !== null && $link->event->status === EventStatus::Draft) {
            return null;
        }

        $up = in_array($link->status, [
            ServerLinkStatus::Ready,
            ServerLinkStatus::Provisioning,
        ], true);

        // A warmup/live match server counts too, even when the link's own
        // status still reads NotResponding while the query catches up.
        $match = $link->match;
        $up = $up || ($match !== null && in_array($match->status, [MatchStatus::Warmup, MatchStatus::Live], true));

        if (! $up && ! ($link->queried_at !== null && $link->queried_at->gte(now()->subDay()))) {
            return null;
        }

        return $link->queried_at ?? $link->updated_at;
    }

    /**
     * The compact live readout for the "Zuletzt gespielt" card: only a Ready
     * server that answered its query has a map and a player count.
     *
     * @return array{title: string, map: string, players: int, max_players: int, checked_at: string|null}|null
     */
    private function liveServer(ServerLink $link, GameInstallLinks $installLinks): ?array
    {
        if ($link->status !== ServerLinkStatus::Ready) {
            return null;
        }

        $query = ServerListProjection::dto($link, install: $installLinks)['query'];

        if (! is_array($query)) {
            return null;
        }

        $name = (string) ($query['serverName'] ?? '');

        return [
            'title' => $link->title ?? $name,
            'map' => (string) ($query['map'] ?? ''),
            'players' => (int) ($query['players'] ?? 0),
            'max_players' => (int) ($query['maxPlayers'] ?? 0),
            'checked_at' => is_string($query['checkedAt'] ?? null) ? $query['checkedAt'] : null,
        ];
    }

    /**
     * @param  Collection<int, Tournament>  $tournaments
     * @return list<array<string, mixed>>
     */
    private function tournamentsForRecipe(GameRecipe $recipe, Collection $tournaments): array
    {
        return array_values($tournaments
            ->filter(fn (Tournament $tournament): bool => $tournament->game !== null
                && $this->gameMatcher->matches($recipe, $tournament->game->slug, $tournament->game->name))
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
        $requirements = $recipe->requirements ?? [];
        $used = [];
        /** @var array<int, GameRecipeFile> $entries */
        $entries = [];

        // Pass 1: exact role+label for every requirement, before any fallback.
        // Otherwise an unmatched requirement (a newly added YAML entry) could
        // steal the row that a later requirement names exactly.
        foreach ($requirements as $index => $requirement) {
            $role = (string) ($requirement['role'] ?? 'content');
            $label = (string) ($requirement['label'] ?? '');

            foreach ($files as $fileIndex => $file) {
                if (! isset($used[$fileIndex]) && $file->role->value === $role && $file->label === $label) {
                    $entries[$index] = $file;
                    $used[$fileIndex] = true;

                    break;
                }
            }
        }

        // Pass 2: same role is the only remaining hint (a renamed label).
        foreach ($requirements as $index => $requirement) {
            if (isset($entries[$index])) {
                continue;
            }

            $role = (string) ($requirement['role'] ?? 'content');

            foreach ($files as $fileIndex => $file) {
                if (! isset($used[$fileIndex]) && $file->role->value === $role) {
                    $entries[$index] = $file;
                    $used[$fileIndex] = true;

                    break;
                }
            }
        }

        $rows = [];

        foreach ($requirements as $index => $requirement) {
            $rows[] = ['requirement' => $requirement, 'entry' => $entries[$index] ?? null];
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
     * Only absolute http(s) URLs may reach the page. Recipe metadata is
     * authored content (and syncable from an SMB share or git repo), so a
     * `javascript:` or `data:` value must never become a clickable link.
     */
    private function absoluteHttpUrl(mixed $url): ?string
    {
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
        bool $launcherAvailable,
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
            'homepage' => $this->absoluteHttpUrl($recipe->homepage),
            'avatar_converter_url' => $this->absoluteHttpUrl($recipe->metadata['avatar_converter_url'] ?? null),
            'installable' => $this->installable($recipe),
            'install_command' => $recipe->installCommand(),
            'launch_command' => $recipe->launchCommand(),
            'install_deeplink' => $recipe->installDeepLink(),
            'launch_deeplink' => $recipe->launchDeepLink(),
            // The deep link only opens when the agent/launcher is already installed;
            // this is the fallback the card offers when it is not. `null` means no
            // build is published, so the page must not link to an empty download page.
            'launcher_available' => $launcherAvailable,
            'launcher_url' => $launcherAvailable ? route('launcher.index', absolute: false) : null,
            // The one class that decides `launcher_supported`: the shipped
            // launcher build installs the pilot/generic engine and portable
            // recipes, and can open a browser recipe's play_url; the rest keep
            // their agent link.
            'launcher_supported' => app(LauncherSupport::class)->supports($recipe),
            // {{host}} is the LAN address that serves the web game; substitute the
            // request host so a recipe never hardcodes localhost.
            'play_url' => $this->absoluteHttpUrl($recipe->playUrlFor(request()->getHost())),
            // "Ein Server · Nickname · Join/Leave" for a browser recipe.
            'server_note' => $recipe->serverNote(),
            'launch_target' => $recipe->launchTarget(),
            'launch_targets' => $recipe->launchTargets(),
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
