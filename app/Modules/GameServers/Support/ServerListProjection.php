<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\CurrentEvent;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Contracts\GameInstallLinks;
use App\Modules\GameServers\Contracts\ServerOccupants;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Http\GameServerPageController;
use App\Modules\GameServers\Http\GlobalServerPageController;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Infoscreen\Support\ScenePayload;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Support\BracketMatchProjection;
use App\Modules\Tournaments\Support\EntryRoster;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The single `ServerLink` -> wire DTO projection for the event's game
 * servers, shared by the public server list page
 * ({@see GameServerPageController}) and the infoscreen's Servers scene
 * ({@see ScenePayload}). Mirrors how ScheduleProjection/SeatProjection are
 * shared between a participant page and its scene.
 *
 * Ready links are surfaced, plus NotResponding ones (so players see that the
 * server is not answering instead of a silent gap) and event-level preset servers
 * that are still starting. A Pending/Failed/Stopped server has nothing joinable,
 * so listing it would just be noise (same "Ready only" spirit the
 * upcoming-matches scene applies to matches).
 *
 * `estimate` (roadmap 6.7) carries the same RAM readout
 * {@see GuardrailPolicy} enforces server-side — shown here as a calm,
 * ongoing footprint readout (this list is Ready-only, so it is never the
 * pre-start moment the match page's estimate is; see
 * {@see BracketMatchProjection}), so orgas
 * browsing the public list can see at a glance how much of the per-instance
 * cap each running server actually uses.
 *
 * `install` answers "how does a player get this game?": the published recipe
 * that installs it and the one-click launcher join, or null while no recipe
 * fits ({@see GameInstallLinks}). Every builder resolves the contract once and
 * hands it down, so a list of servers never turns into one catalogue query per
 * row.
 *
 * `occupants` answers "who of the LANoMAT accounts is on it?" ({@see ServerOccupants}):
 * null while Presence is off or nobody is logged in, otherwise the accounts the
 * viewer may see, capped. The event that scopes "who" is the event of the list
 * the row belongs to; the global browser and the permanent servers use the
 * current event (see the contract's docblock). Both resolvers live for one web
 * request; every builder takes them once and hands them down.
 */
class ServerListProjection
{
    /**
     * The install-link resolver. Inside a routed web request every caller
     * shares one instance ({@see self::perRequest()}): the rows of a page and the shell's
     * server badge ask the same "which recipe installs this game?" question. A caller that
     * already holds one (a builder, a controller) passes it in, which keeps a loop to one
     * catalogue read.
     */
    public static function installLinks(?GameInstallLinks $install = null): GameInstallLinks
    {
        return $install ?? app(GameInstallLinks::class);
    }

    /**
     * The occupant resolver, with the same lifetime as {@see self::installLinks()}: one
     * location-snapshot read and one visibility computation per page, never one per row.
     */
    public static function occupants(?ServerOccupants $occupants = null): ServerOccupants
    {
        return $occupants ?? app(ServerOccupants::class);
    }

    /**
     * The lifetime of both bindings. Inside a routed web request the resolver is kept on the
     * request, so a page's rows and the shell's server badge share one catalogue read and one
     * location snapshot, and the next request starts fresh. Everywhere else (a queue worker,
     * the scheduler, a console command) every resolve builds a new one: a worker lives for
     * days, and a resolver kept there would never see a recipe that is published later. A
     * caller outside a request that builds several rows therefore takes the resolver once and
     * hands it down, as every builder in this class does.
     *
     * @template T of GameInstallLinks|ServerOccupants
     *
     * @param  class-string<T>  $abstract
     * @param  callable(): T  $build
     * @return T
     */
    public static function perRequest(string $abstract, callable $build): GameInstallLinks|ServerOccupants
    {
        $request = self::request();

        if ($request === null) {
            return $build();
        }

        $key = 'lanomat.resolver.'.$abstract;
        $cached = $request->attributes->get($key);

        if ($cached instanceof $abstract) {
            /** @var T $cached */
            return $cached;
        }

        /** @var T $resolver */
        $resolver = $build();
        $request->attributes->set($key, $resolver);

        return $resolver;
    }

    /** The routed web request of this process, or null in the console, a queue worker or a test arranging data. */
    private static function request(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        // The console kernel binds a synthetic request for a worker's whole lifetime: only a
        // routed one is a real page.
        return $request->route() !== null ? $request : null;
    }

    /**
     * One row's occupant line, or null when nobody could be shown: no logged-in
     * viewer, no event to scope Presence by, or Presence itself off.
     *
     * @return array{people: list<array{id: int, name: string, avatarUrl: string|null}>, more: int}|null
     */
    public static function occupantsFor(ServerLink $link, ?Event $event, ?User $viewer, ?ServerOccupants $occupants = null): ?array
    {
        if ($event === null || $viewer === null) {
            return null;
        }

        return self::occupants($occupants)->forServer($link->id, $event, $viewer);
    }

    /** The LAN that is running now: the event scope of the occupant line of permanent and browser rows. */
    private static function currentEvent(): ?Event
    {
        return app(CurrentEvent::class)->get();
    }

    /**
     * The event's server list as one viewer may see it, occupant line included (`$viewer`
     * null means "no occupant line": the beamer scene and the bearer APIs ask that way).
     *
     * @return list<array<string, mixed>>
     */
    public static function forEvent(Event $event, bool $withPlayerList = false, ?GameInstallLinks $install = null, ?User $viewer = null): array
    {
        $install = self::installLinks($install);
        $occupants = self::occupants();

        return array_values(self::linksForEvent($event)->map(fn (ServerLink $link): array => self::dto($link, $withPlayerList, $install, $occupants, $event, $viewer) + ['description' => $link->description])->all());
    }

    /**
     * The event list as seen by one viewer, for the native client: the same query and DTO as
     * {@see self::forEvent()}, but a server bound to a single match is only listed for people
     * who play in that match (either roster) and for orga. Stricter than the public web page on
     * purpose: other players' match servers are not the viewer's business.
     *
     * No occupant line: this builds the native client's bearer answers, and "Wo ist wer" has its
     * own endpoint for people.
     *
     * @return list<array<string, mixed>>
     */
    public static function forEventViewedBy(Event $event, User $viewer, ?GameInstallLinks $install = null): array
    {
        $install = self::installLinks($install);

        return array_values(self::linksForEventViewedBy($event, $viewer)->map(fn (ServerLink $link): array => self::dto($link, install: $install) + ['description' => $link->description])->all());
    }

    /**
     * The servers behind {@see self::forEventViewedBy()}, as models: a caller that only needs
     * to know which servers a viewer may see (an install plan) does not pay for the rows.
     *
     * @return Collection<int, ServerLink>
     */
    public static function linksForEventViewedBy(Event $event, User $viewer): Collection
    {
        return self::linksForEvent($event)->filter(function (ServerLink $link) use ($viewer): bool {
            if ($link->match_id === null || $viewer->isOrga()) {
                return true;
            }

            return self::viewerPlaysIn($link->match, $viewer);
        })->values();
    }

    /** The game a server runs: its tournament's game, otherwise its own. */
    public static function gameOf(ServerLink $link): ?Game
    {
        $tournament = self::tournamentFor($link);

        return $tournament !== null ? $tournament->game : $link->game;
    }

    /** True when the viewer is on the roster of either entry of the match. */
    public static function viewerPlaysIn(?GameMatch $match, User $viewer): bool
    {
        if ($match === null) {
            return false;
        }

        foreach ([$match->entry1, $match->entry2] as $entry) {
            if ($entry !== null && in_array($viewer->id, array_map('intval', EntryRoster::userIdsFor($entry)), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, ServerLink>
     */
    public static function linksForEvent(Event $event): Collection
    {
        return ServerLink::query()
            ->where(function ($query): void {
                // Ready and NotResponding servers are always listed; a server the orga just
                // started from a preset (event-level) is listed while it starts up.
                $query->whereIn('status', [ServerLinkStatus::Ready, ServerLinkStatus::NotResponding])
                    ->orWhere(fn ($q) => $q->whereNotNull('event_id')->where('status', ServerLinkStatus::Provisioning));
            })
            ->where(function ($query) use ($event): void {
                $query->where('event_id', $event->id)->orWhereHas(
                    'match',
                    fn ($q) => $q->whereHas(
                        'tournament',
                        fn ($q) => $q->where('event_id', $event->id),
                    ),
                )->orWhereHas(
                    'tournament',
                    fn ($q) => $q->where('event_id', $event->id),
                );
            })
            ->with(['match.tournament.game', 'match.entry1', 'match.entry2', 'tournament.game', 'game'])
            ->orderBy('id')
            ->get();
    }

    /**
     * The permanent servers for the participant page (all sources, same wire shape as the event
     * list). Unlike the event list they are shown even while offline or not responding, because
     * "is it up?" is the point; only a server still being created, or one whose creation failed,
     * is left out.
     *
     * Permanent servers belong to no event, so their occupant line is scoped to the current
     * event — the people on them are the people at the LAN that is running now. `$viewer` null
     * means "no occupant line" (the MCP API asks that way).
     *
     * @return list<array<string, mixed>>
     */
    public static function forPermanent(?GameInstallLinks $install = null, ?User $viewer = null): array
    {
        $install = self::installLinks($install);
        $occupants = self::occupants();
        $occupantEvent = self::currentEvent();

        return array_values(self::permanentLinks()->map(fn (ServerLink $link): array => self::permanentDto($link, install: $install, occupants: $occupants, occupantEvent: $occupantEvent, occupantViewer: $viewer))->all());
    }

    /**
     * The flat list behind the global server browser ({@see GlobalServerPageController}):
     * every server the event lists of the publicly visible, non-archived events show,
     * plus the permanent servers. Draft and archived events are out — a draft event is
     * not public at all, and an archived one is history, not "is it running right now".
     *
     * Each row is the same wire shape as `forEvent()`/`forPermanent()` plus the event it
     * belongs to (name/slug for the row's event link, null for permanent servers) and the
     * `permanent` flag. Grouping by game stays in the page; the game/search filter is the
     * controller's (see {@see self::searchText()}), so this stays a pure visibility projection.
     *
     * The occupant line follows the current event for every row, past events' servers included:
     * the snapshot is "where is everybody now", and a past event's participants are not the
     * people sitting at this LAN. `$viewer` null means "no occupant line": the launcher page
     * asks that way, the server browser hands its signed-in user down.
     *
     * @return list<array<string, mixed>>
     */
    public static function forGlobalBrowser(bool $withPlayerList = false, ?GameInstallLinks $install = null, ?User $viewer = null): array
    {
        $install = self::installLinks($install);
        $occupants = self::occupants();
        $occupantEvent = $viewer !== null ? self::currentEvent() : null;

        $rows = [];

        foreach (self::linksForGlobalBrowser() as $entry) {
            $link = $entry['link'];
            $event = $entry['event'];

            $rows[] = $event !== null
                ? self::dto($link, $withPlayerList, $install, $occupants, $occupantEvent, $viewer) + [
                    'event' => ['name' => $event->name, 'slug' => $event->slug],
                    'permanent' => false,
                ]
                : self::permanentDto($link, $withPlayerList, $install, $occupants, $occupantEvent, $viewer) + [
                    'event' => null,
                    'permanent' => true,
                ];
        }

        // Deterministic order for the browser and its tests: game, then the shown name.
        usort($rows, self::compareRows(...));

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function compareRows(array $a, array $b): int
    {
        return [
            Str::lower((string) ($a['game'] ?? '')),
            Str::lower(self::shownName($a)),
        ] <=> [
            Str::lower((string) ($b['game'] ?? '')),
            Str::lower(self::shownName($b)),
        ];
    }

    /**
     * One searchable text of a browser row, so the page can match a query against the
     * things a player would type (name, game, map, event, address) without opening the
     * server. Backend-side so the filter is testable and the payload stays small.
     *
     * @param  array<string, mixed>  $row
     */
    public static function searchText(array $row): string
    {
        $query = is_array($row['query'] ?? null) ? $row['query'] : [];
        $event = is_array($row['event'] ?? null) ? $row['event'] : [];

        $parts = [];
        foreach ([
            self::shownName($row),
            $row['game'] ?? null,
            $query['serverName'] ?? null,
            $query['map'] ?? null,
            $row['matchLabel'] ?? null,
            $row['description'] ?? null,
            $row['address'] ?? null,
            $event['name'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $parts[] = $candidate;
            }
        }

        if (is_int($row['port'] ?? null)) {
            $parts[] = (string) $row['port'];
        }

        return Str::lower(implode(' ', $parts));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function shownName(array $row): string
    {
        $query = is_array($row['query'] ?? null) ? $row['query'] : [];

        foreach ([$row['title'] ?? null, $query['serverName'] ?? null, $row['game'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public static function permanentDto(
        ServerLink $link,
        bool $withPlayerList = false,
        ?GameInstallLinks $install = null,
        ?ServerOccupants $occupants = null,
        ?Event $occupantEvent = null,
        ?User $occupantViewer = null,
    ): array {
        return self::dto($link, $withPlayerList, $install, $occupants, $occupantEvent, $occupantViewer) + [
            'description' => $link->description,
            'source' => $link->source->value,
        ];
    }

    /**
     * The servers the global browser lists, as models: the servers of every public,
     * non-archived event (each with that event) and then the permanent servers (event null),
     * every server once.
     *
     * @return list<array{link: ServerLink, event: Event|null}>
     */
    public static function linksForGlobalBrowser(): array
    {
        $entries = [];
        $seen = [];

        $events = Event::query()
            ->publiclyVisible()
            ->where('status', '!=', EventStatus::Archived)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();

        foreach ($events as $event) {
            foreach (self::linksForEvent($event) as $link) {
                if (isset($seen[$link->id])) {
                    continue;
                }

                $seen[$link->id] = true;
                $entries[] = ['link' => $link, 'event' => $event];
            }
        }

        foreach (self::permanentLinks() as $link) {
            if (isset($seen[$link->id])) {
                continue;
            }

            $seen[$link->id] = true;
            $entries[] = ['link' => $link, 'event' => null];
        }

        return $entries;
    }

    /**
     * @return Collection<int, ServerLink>
     */
    public static function permanentLinks(): Collection
    {
        return ServerLink::query()
            ->permanent()
            ->whereIn('status', [
                ServerLinkStatus::Ready,
                ServerLinkStatus::NotResponding,
                ServerLinkStatus::Offline,
                ServerLinkStatus::Provisioning,
            ])
            ->with('game')
            ->orderBy('title')
            ->orderBy('id')
            ->get();
    }

    /**
     * The who-is-on-it list (`query.playerList`, in-game nicknames) is included only when
     * `$withPlayerList` is set; callers pass it for logged-in viewers on their own pages and
     * never for guests, projections (infoscreen, overlays) or broadcasts.
     *
     * `occupantEvent`/`occupantViewer` decide the `occupants` line: both null (or one of them)
     * means the row carries `occupants: null` — no logged-in viewer, or no event to scope
     * Presence by. A builder passes the event of its list and the viewer it already has.
     *
     * @return array<string, mixed>
     */
    public static function dto(
        ServerLink $link,
        bool $withPlayerList = false,
        ?GameInstallLinks $install = null,
        ?ServerOccupants $occupants = null,
        ?Event $occupantEvent = null,
        ?User $occupantViewer = null,
    ): array {
        $tournament = self::tournamentFor($link);
        $game = $tournament !== null ? $tournament->game : $link->game;
        $connectString = PelicanJoinLink::for($link->join_info);
        $connectIsLink = JoinLinkTemplates::isAllowedUrl($connectString);
        $joinOptions = JoinLinkTemplates::optionsFor($game, $link->join_info);

        return [
            'id' => $link->id,
            'game' => $game?->name,
            // The stable game/recipe slug, so a client can map a server to its
            // install recipe (the launcher joins via the recipe's launch target).
            'gameSlug' => $game?->slug,
            'title' => $link->title,
            'matchLabel' => self::matchLabel($link, $tournament),
            'address' => $link->join_info->address,
            'port' => $link->join_info->port,
            'connectString' => $connectString,
            'connectIsLink' => $connectIsLink,
            'status' => $link->status->value,
            'query' => self::queryFor($link, $withPlayerList),
            'estimate' => $link->permanent ? null : self::estimateFor($game),
            'installHint' => self::installHintFor($game),
            'joinOptions' => $joinOptions,
            // The recipe that installs this game and the one-click launcher
            // join, or null while no published recipe fits the game.
            'install' => self::installLinks($install)->forServer(
                $game?->slug,
                $game?->name,
                $link->join_info->address,
                $link->join_info->port,
                self::serverJoinUrl($joinOptions, $connectString, $connectIsLink),
            ),
            // Who of the LANoMAT accounts the viewer may see on it, or null.
            'occupants' => self::occupantsFor($link, $occupantEvent, $occupantViewer, $occupants),
        ];
    }

    /**
     * The server's own join URL, in the order the launcher page has always
     * used: the first per-game deep link, otherwise a connect string that is
     * itself a link. A plain `address:port` is copy data, not a URL.
     *
     * @param  list<array{key: string, label: string, url: string, hint: string}>  $joinOptions
     */
    private static function serverJoinUrl(array $joinOptions, string $connectString, bool $connectIsLink): ?string
    {
        foreach ($joinOptions as $option) {
            if (JoinLinkTemplates::isAllowedUrl($option['url'])) {
                return $option['url'];
            }
        }

        return $connectIsLink && JoinLinkTemplates::isAllowedUrl($connectString) ? $connectString : null;
    }

    /**
     * The last A2S answer (server name, map, players) or null when the server was never
     * queried (no query check, or still starting).
     *
     * @return array{serverName: string, map: string, players: int, maxPlayers: int, checkedAt: string|null, playerList?: list<array{name: string, score: int|null, seconds: int|null}>}|null
     */
    private static function queryFor(ServerLink $link, bool $withPlayerList = false): ?array
    {
        $info = $link->query_info;

        // A switched-off server has no players, whatever it answered last.
        if ($link->status === ServerLinkStatus::Offline || $info === null || $info === []) {
            return null;
        }

        $query = [
            'serverName' => (string) ($info['name'] ?? ''),
            'map' => (string) ($info['map'] ?? ''),
            'players' => (int) ($info['players'] ?? 0),
            'maxPlayers' => (int) ($info['max_players'] ?? 0),
            'checkedAt' => $link->queried_at?->toIso8601String(),
        ];

        if (! $withPlayerList) {
            return $query;
        }

        // Servers that only volunteer names (Minecraft's status sample) get them without a score.
        $names = is_array($info['player_names'] ?? null)
            ? array_map(static fn (mixed $name): array => ['name' => $name], $info['player_names'])
            : [];
        $rows = is_array($info['player_list'] ?? null) ? $info['player_list'] : $names;

        return $query + ['playerList' => PlayerList::normalize($rows)];
    }

    /**
     * Surfaces the game's "So kommst du ran" install hint (roadmap 7.5) on
     * the participant server list — null (rendered as nothing, no empty
     * placeholder) when the game has no hint configured, mirroring
     * {@see self::estimateFor()}'s null-when-no-game rule.
     *
     * @return array{steamUrl: ?string, shareUrl: ?string, versionNote: ?string}|null
     */
    private static function installHintFor(?Game $game): ?array
    {
        if ($game === null || $game->install_hint->isEmpty()) {
            return null;
        }

        return [
            'steamUrl' => $game->install_hint->steamUrl,
            'shareUrl' => $game->install_hint->shareUrl,
            'versionNote' => $game->install_hint->versionNote,
        ];
    }

    /**
     * @return array{ramMb: int, maxRamMb: int, overCap: bool}|null
     */
    private static function estimateFor(?Game $game): ?array
    {
        if ($game === null) {
            return null;
        }

        $config = EffectiveConfig::resolve($game, presetKey: null, uploadedPath: null);
        $ramMb = ResourceEstimate::for($game, $config);
        $maxRamMb = (int) config('services.pelican.max_ram_mb');

        return [
            'ramMb' => $ramMb,
            'maxRamMb' => $maxRamMb,
            'overCap' => $ramMb > $maxRamMb,
        ];
    }

    private static function tournamentFor(ServerLink $link): ?Tournament
    {
        if ($link->match !== null) {
            return $link->match->tournament;
        }

        return $link->tournament;
    }

    private static function matchLabel(ServerLink $link, ?Tournament $tournament): ?string
    {
        if ($tournament === null) {
            return null;
        }

        $match = $link->match;

        if ($match instanceof GameMatch) {
            return trans('gameservers.page.match_label', ['tournament' => $tournament->name, 'round' => $match->round]);
        }

        return $tournament->name;
    }
}
