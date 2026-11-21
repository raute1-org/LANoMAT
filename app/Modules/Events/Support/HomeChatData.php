<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Modules\Events\Models\Event;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\LauncherBuilds;
use App\Modules\GameRecipes\Support\RecipeFileMatcher;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\JoinLinkTemplates;
use App\Modules\GameServers\Support\ServerListProjection;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Support\Collection;

/**
 * Server-side data for the start page's chat hero (`components/home/HomeChat`).
 *
 * The hero answers two first-visitor questions deterministically in the
 * browser: "which games are there?" and "which servers are running?". This
 * class only prepares the small, fixed-size answer sets — it never talks to
 * an LLM and never exposes anything the viewer could not already open:
 *
 * - games: published recipes only ({@see GameRecipe::scopePublished()}), with
 *   the same LAN-readiness rule the games library uses (a required LAN file is
 *   present, or a server for the game is running);
 * - servers: the current event's ready servers plus the permanent ones, via
 *   {@see ServerListProjection} so the chat cards and the server pages never
 *   drift apart.
 *
 * Guests get the public catalogue (published recipes) but no servers (the
 * server pages are login-gated) and no event data at all.
 *
 * Both optional modules are reached through `Modules::enabled()` guards, so a
 * `core` install renders the greeting and chips with empty answers.
 */
final class HomeChatData
{
    public const MAX_GAMES = 6;

    public const MAX_SERVERS = 8;

    /**
     * Recipes the launcher pilot can actually install. Everything else is a
     * library entry only — the chat must never offer a launcher download that
     * ends in a catalog-only game (see the launcher roadmap, L46).
     *
     * @var list<string>
     */
    private const LAUNCHER_SUPPORTED_SLUGS = [
        'unreal-tournament-2004',
    ];

    /** @var list<string> */
    private const SUGGESTIONS = [
        'Was gibt es zu spielen?',
        'Welche Server laufen?',
    ];

    public function __construct(
        private readonly Modules $modules,
        private readonly LauncherBuilds $launcher,
        private readonly RecipeFileMatcher $matcher,
    ) {}

    /**
     * @return array{
     *     greeting: string,
     *     suggestions: list<string>,
     *     games: list<array<string, mixed>>,
     *     servers: list<array<string, mixed>>
     * }
     */
    public function forEvent(?Event $event, bool $guest = false): array
    {
        $links = $this->serverLinks($event, $guest);

        return [
            'greeting' => $this->greeting($event, $guest),
            'suggestions' => self::SUGGESTIONS,
            'games' => $this->games($event, $links),
            // Guests never see aggregated server data: the server pages are
            // login-gated and the joins would lead nowhere useful.
            'servers' => $guest ? [] : $this->serverCards($links, $event),
        ];
    }

    private function greeting(?Event $event, bool $guest): string
    {
        if ($guest) {
            return 'Willkommen bei LANoMAT! Frage mich nach Spielen — melde dich an, um laufende Server zu sehen und loszulegen.';
        }

        if ($event !== null) {
            return "Hi! Willkommen bei {$event->name}. Frage mich nach Spielen oder laufenden Servern.";
        }

        return 'Hi! Ich bin der LANoMAT-Assistent. Frage mich nach Spielen oder laufenden Servern.';
    }

    /**
     * The event's current servers plus the permanent ones, deduplicated and
     * capped. Ordering is deterministic (running first, then by title), so a
     * reload never reshuffles the chat cards.
     *
     * @return Collection<int, ServerLink>
     */
    private function serverLinks(?Event $event, bool $guest): Collection
    {
        if ($guest || ! $this->modules->enabled(Module::GameServers)) {
            return new Collection;
        }

        /** @var Collection<int, ServerLink> $links */
        $links = $event === null
            ? ServerListProjection::permanentLinks()
            : ServerListProjection::linksForEvent($event)->merge(ServerListProjection::permanentLinks());

        return $links
            ->unique('id')
            ->filter(fn (ServerLink $link): bool => in_array($link->status, [
                ServerLinkStatus::Ready,
                ServerLinkStatus::NotResponding,
                ServerLinkStatus::Provisioning,
            ], true))
            ->sortBy(fn (ServerLink $link): string => sprintf(
                '%d-%s',
                $this->statusRank($link->status),
                mb_strtolower((string) $link->title),
            ))
            ->values();
    }

    private function statusRank(ServerLinkStatus $status): int
    {
        return match ($status) {
            ServerLinkStatus::Ready => 0,
            ServerLinkStatus::NotResponding => 1,
            default => 2,
        };
    }

    /**
     * Published recipes, capped to the cards the chat can show without turning
     * into a second game library. LAN readiness mirrors the games library.
     *
     * @param  Collection<int, ServerLink>  $links
     * @return list<array<string, mixed>>
     */
    private function games(?Event $event, Collection $links): array
    {
        if (! $this->modules->enabled(Module::GameRecipes)) {
            return [];
        }

        $recipes = GameRecipe::query()
            ->published()
            ->with('files')
            ->orderBy('title')
            ->limit(self::MAX_GAMES)
            ->get();

        if ($recipes->isEmpty()) {
            return [];
        }

        $matches = $this->matchedFiles($event, $recipes);
        $launcherUrl = $this->launcher->builds() === [] ? null : route('launcher.index', absolute: false);

        return array_values($recipes->map(function (GameRecipe $recipe) use ($matches, $links, $launcherUrl): array {
            $recipeServers = $this->serversForRecipe($recipe, $links);
            $lanReady = $recipeServers !== [];

            foreach ($recipe->files as $entry) {
                if ($entry->required && isset($matches[$recipe->id][$entry->id])) {
                    $lanReady = true;

                    break;
                }
            }

            return [
                'slug' => $recipe->slug,
                'title' => $recipe->title,
                'summary' => $recipe->summary,
                'version' => $recipe->version,
                'lan_ready' => $lanReady,
                'server_count' => count($recipeServers),
                'installable' => $recipe->files->isNotEmpty() || ($recipe->requirements ?? []) !== [],
                // Only the pilot recipe has a working launcher install path;
                // every other published recipe is "Ansehen" in the chat card.
                'launcher_supported' => in_array($recipe->slug, self::LAUNCHER_SUPPORTED_SLUGS, true),
                // Null when no launcher build is published, so the card never
                // offers a download the /launcher page cannot deliver.
                'launcher_url' => $launcherUrl,
                'install_deeplink' => $recipe->installDeepLink(),
            ];
        })->all());
    }

    /**
     * Approved LAN files matched to the recipes with ONE query (same rule as the
     * games library: a pinned upload wins, otherwise the largest/newest filename
     * match). Only the ids are kept — the chat card needs a boolean, not files.
     *
     * @param  Collection<int, GameRecipe>  $recipes
     * @return array<int, array<int, true>> recipe id => recipe-file id => true
     */
    private function matchedFiles(?Event $event, Collection $recipes): array
    {
        $candidates = $this->matcher->query($event)
            ->select(['id', 'original_name', 'size_bytes'])
            ->orderByDesc('size_bytes')
            ->orderByDesc('id')
            ->limit(2000)
            ->get()
            ->keyBy('id');

        $result = [];

        foreach ($recipes as $recipe) {
            foreach ($recipe->files as $entry) {
                $shared = $entry->shared_file_id !== null ? $candidates->get($entry->shared_file_id) : null;

                if ($shared === null) {
                    $patterns = $entry->patterns();

                    if ($patterns !== []) {
                        $shared = $candidates->first(
                            fn ($candidate): bool => $this->matcher->nameMatches((string) $candidate->original_name, $patterns),
                        );
                    }
                }

                if ($shared !== null) {
                    $result[$recipe->id][$entry->id] = true;
                }
            }
        }

        return $result;
    }

    /**
     * Servers that belong to a recipe, matched by the same game-name terms the
     * games library uses (slug, title, aliases).
     *
     * @param  Collection<int, ServerLink>  $links
     * @return list<ServerLink>
     */
    private function serversForRecipe(GameRecipe $recipe, Collection $links): array
    {
        $terms = $recipe->searchTerms();

        return array_values($links
            ->filter(function (ServerLink $link) use ($terms): bool {
                $game = $link->match?->tournament->game ?? $link->game;

                return $game !== null && $this->matchesTerms($terms, $game->name, $game->slug);
            })
            ->all());
    }

    /**
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
     * @param  Collection<int, ServerLink>  $links
     * @return list<array<string, mixed>>
     */
    private function serverCards(Collection $links, ?Event $event): array
    {
        return array_values($links
            ->take(self::MAX_SERVERS)
            ->map(function (ServerLink $link) use ($event): array {
                $dto = ServerListProjection::dto($link);
                $query = is_array($dto['query'] ?? null) ? $dto['query'] : [];
                $game = is_string($dto['game'] ?? null) ? $dto['game'] : null;
                $title = is_string($dto['title'] ?? null) && $dto['title'] !== '' ? $dto['title'] : '';

                if ($title === '') {
                    $title = is_string($query['serverName'] ?? null) && $query['serverName'] !== ''
                        ? $query['serverName']
                        : ($game ?? 'Server');
                }

                return [
                    'title' => $title,
                    'game' => $game,
                    'map' => is_string($query['map'] ?? null) && $query['map'] !== '' ? $query['map'] : null,
                    'players' => is_numeric($query['players'] ?? null) ? (int) $query['players'] : null,
                    'max' => is_numeric($query['maxPlayers'] ?? null) ? (int) $query['maxPlayers'] : null,
                    'status' => is_string($dto['status'] ?? null) ? $dto['status'] : 'pending',
                    'join_url' => $this->joinUrl($dto),
                    // Event-level and tournament servers link back to the
                    // event's server page; permanent ones have no event.
                    'event_slug' => $link->permanent ? null : $event?->slug,
                ];
            })
            ->all());
    }

    /**
     * A joinable URL only when the game actually offers one (a per-game deep
     * link) or the server carries an explicit link; a bare `address:port` would
     * otherwise become a fake `steam://connect` URL in the card.
     *
     * @param  array<string, mixed>  $dto
     */
    private function joinUrl(array $dto): ?string
    {
        $options = is_array($dto['joinOptions'] ?? null) ? $dto['joinOptions'] : [];

        foreach ($options as $option) {
            if (is_array($option) && is_string($option['url'] ?? null) && $option['url'] !== '') {
                return $option['url'];
            }
        }

        if (($dto['connectIsLink'] ?? false) === true && is_string($dto['connectString'] ?? null)) {
            $connect = $dto['connectString'];
            $scheme = parse_url($connect, PHP_URL_SCHEME);

            if (is_string($scheme) && in_array(mb_strtolower($scheme), JoinLinkTemplates::ALLOWED_SCHEMES, true)) {
                return $connect;
            }
        }

        return null;
    }
}
