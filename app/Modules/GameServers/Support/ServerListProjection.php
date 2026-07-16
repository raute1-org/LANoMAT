<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Http\GameServerPageController;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Infoscreen\Support\ScenePayload;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Support\BracketMatchProjection;

/**
 * The single `ServerLink` -> wire DTO projection for the event's game
 * servers, shared by the public server list page
 * ({@see GameServerPageController}) and the infoscreen's Servers scene
 * ({@see ScenePayload}). Mirrors how ScheduleProjection/SeatProjection are
 * shared between a participant page and its scene.
 *
 * Only Ready links are surfaced: a Pending/Provisioning/Failed/Stopped
 * server has nothing joinable yet, so listing it would just be noise (same
 * "Ready only" rule the upcoming-matches scene applies to matches).
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
        $links = ServerLink::query()
            ->where('status', ServerLinkStatus::Ready)
            ->where(function ($query) use ($event): void {
                $query->whereHas(
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
            ->with(['match.tournament.game', 'tournament.game'])
            ->orderBy('id')
            ->get();

        return array_values($links->map(fn (ServerLink $link): array => self::dto($link))->all());
    }

    /**
     * @return array<string, mixed>
     */
    private static function dto(ServerLink $link): array
    {
        $tournament = self::tournamentFor($link);
        $game = $tournament?->game;

        return [
            'id' => $link->id,
            'game' => $game?->name,
            'matchLabel' => self::matchLabel($link, $tournament),
            'address' => $link->join_info->address,
            'port' => $link->join_info->port,
            'connectString' => PelicanJoinLink::for($link->join_info),
            'status' => $link->status->value,
            'estimate' => self::estimateFor($game),
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
