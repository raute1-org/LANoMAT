<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Http\GameServerPageController;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Infoscreen\Support\ScenePayload;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;

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

        return [
            'id' => $link->id,
            'game' => $tournament?->game?->name,
            'matchLabel' => self::matchLabel($link, $tournament),
            'address' => $link->join_info->address,
            'port' => $link->join_info->port,
            'connectString' => PelicanJoinLink::for($link->join_info),
            'status' => $link->status->value,
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
