<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Http\GameServerPageController;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Infoscreen\Support\ScenePayload;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Support\BracketMatchProjection;
use App\Modules\Tournaments\Support\EntryRoster;
use Illuminate\Database\Eloquent\Collection;

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
 */
class ServerListProjection
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forEvent(Event $event): array
    {
        return array_values(self::linksForEvent($event)->map(fn (ServerLink $link): array => self::dto($link))->all());
    }

    /**
     * The event list as seen by one viewer, for the native client: the same query and DTO as
     * {@see self::forEvent()}, but a server bound to a single match is only listed for people
     * who play in that match (either roster) and for orga. Stricter than the public web page on
     * purpose: other players' match servers are not the viewer's business.
     *
     * @return list<array<string, mixed>>
     */
    public static function forEventViewedBy(Event $event, User $viewer): array
    {
        $links = self::linksForEvent($event)->filter(function (ServerLink $link) use ($viewer): bool {
            if ($link->match_id === null || $viewer->isOrga()) {
                return true;
            }

            return self::viewerPlaysIn($link->match, $viewer);
        });

        return array_values($links->map(fn (ServerLink $link): array => self::dto($link) + ['description' => $link->description])->all());
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
     * @return list<array<string, mixed>>
     */
    public static function forPermanent(): array
    {
        return array_values(self::permanentLinks()->map(fn (ServerLink $link): array => self::permanentDto($link))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function permanentDto(ServerLink $link): array
    {
        return self::dto($link) + [
            'description' => $link->description,
            'source' => $link->source->value,
        ];
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
     * @return array<string, mixed>
     */
    public static function dto(ServerLink $link): array
    {
        $tournament = self::tournamentFor($link);
        $game = $tournament !== null ? $tournament->game : $link->game;

        return [
            'id' => $link->id,
            'game' => $game?->name,
            'title' => $link->title,
            'matchLabel' => self::matchLabel($link, $tournament),
            'address' => $link->join_info->address,
            'port' => $link->join_info->port,
            'connectString' => PelicanJoinLink::for($link->join_info),
            'connectIsLink' => str_contains(PelicanJoinLink::for($link->join_info), '://'),
            'status' => $link->status->value,
            'query' => self::queryFor($link),
            'estimate' => $link->permanent ? null : self::estimateFor($game),
            'installHint' => self::installHintFor($game),
        ];
    }

    /**
     * The last A2S answer (server name, map, players) or null when the server was never
     * queried (no query check, or still starting).
     *
     * @return array{serverName: string, map: string, players: int, maxPlayers: int, checkedAt: string|null}|null
     */
    private static function queryFor(ServerLink $link): ?array
    {
        $info = $link->query_info;

        // A switched-off server has no players, whatever it answered last.
        if ($link->status === ServerLinkStatus::Offline || $info === null || $info === []) {
            return null;
        }

        return [
            'serverName' => (string) ($info['name'] ?? ''),
            'map' => (string) ($info['map'] ?? ''),
            'players' => (int) ($info['players'] ?? 0),
            'maxPlayers' => (int) ($info['max_players'] ?? 0),
            'checkedAt' => $link->queried_at?->toIso8601String(),
        ];
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
