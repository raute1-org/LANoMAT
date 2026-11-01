<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\ServerListProjection;
use App\Modules\Presence\Contracts\GameServerDirectory;
use App\Modules\Presence\Domain\ServerRoster;
use Illuminate\Support\Facades\Gate;

/**
 * The presence read model's view of the game servers, built on the same projections the servers
 * API uses, so what a person may see and the join link they get are exactly those of
 * `GET servers` and `GET events/{event}/servers`. Bound only while GameServers and Presence are
 * on, otherwise the null directory answers.
 */
class NativeGameServerDirectory implements GameServerDirectory
{
    public function rosters(): array
    {
        $links = ServerLink::query()
            ->whereIn('status', [ServerLinkStatus::Ready, ServerLinkStatus::NotResponding])
            ->with(['game', 'match.tournament.game', 'tournament.game'])
            ->get();

        return array_values($links
            ->filter(fn (ServerLink $link): bool => $link->join_info->address !== null && $link->join_info->port !== null)
            ->map(function (ServerLink $link): ServerRoster {
                $tournament = $link->match !== null ? $link->match->tournament : $link->tournament;
                $names = $link->query_info['player_names'] ?? [];

                return new ServerRoster(
                    linkId: $link->id,
                    game: ($tournament !== null ? $tournament->game : $link->game)?->name,
                    title: $link->title,
                    address: $link->join_info->address,
                    port: $link->join_info->port,
                    playerNames: is_array($names) ? array_values(array_filter($names, 'is_string')) : [],
                );
            })
            ->all());
    }

    public function visibleServers(User $viewer, Event $event): array
    {
        $rows = ServerListProjection::forEventViewedBy($event, $viewer);

        if (Gate::forUser($viewer)->allows('viewPermanent', ServerLink::class)) {
            foreach (ServerListProjection::permanentLinks() as $link) {
                $rows[] = ServerListProjection::permanentDto($link);
            }
        }

        $servers = [];

        foreach ($rows as $row) {
            $dto = NativeServerDto::from($row);
            $id = $dto['id'] ?? null;

            if (! is_int($id)) {
                continue;
            }

            $servers[$id] = [
                'title' => $dto['title'] ?? null,
                'address' => $dto['address'] ?? null,
                'port' => $dto['port'] ?? null,
                'joinUrl' => $dto['joinUrl'] ?? null,
                'copyText' => $dto['copyText'] ?? null,
            ];
        }

        return $servers;
    }
}
