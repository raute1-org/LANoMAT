<?php

declare(strict_types=1);

namespace App\Modules\Presence\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Friends\Support\FriendService;
use App\Modules\Presence\Contracts\GameServerDirectory;
use App\Modules\Presence\Domain\GameLocation;
use App\Modules\Presence\Domain\VoiceLocation;
use App\Modules\Presence\Jobs\RefreshLocationSnapshotJob;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Teams\Models\TeamMember;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\Support\VoiceJoinLink;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Support\Collection;

/**
 * The read side of "where is everybody": the participants of one event with the locations the
 * cached {@see LocationSnapshot} holds, as one viewer may see them, and the join targets to follow
 * one person. Reads the cache only (a cold cache queues a refresh and shows nobody located).
 *
 * Privacy rules in one place:
 *  - somebody who switched `share_location` off is not listed for others, and cannot be followed
 *    (the same "not found" as a stranger); they always see themselves under `me`;
 *  - somebody blocked either way with the viewer is left out like an opt-out;
 *  - a game server the viewer may not see (another match's server) shows only the game name:
 *    no title, address, port, join link or link id;
 *  - a voice room only its roster may join (a match or team room) is shown, but `roomRef` and the
 *    follow target are only given to people on that roster and to orga.
 */
final class PeopleDirectory
{
    public const int DEFAULT_PER_PAGE = 50;

    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private readonly LocationSnapshotStore $store,
        private readonly GameServerDirectory $servers,
        private readonly Modules $modules,
        private readonly FriendService $friends,
    ) {}

    /**
     * @param  array{team?: bool, friends?: bool, q?: string|null, page?: int, per_page?: int}  $filters
     * @return array{me: array<string, mixed>, data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(User $viewer, Event $event, array $filters = []): array
    {
        $snapshot = $this->snapshot();
        $users = $this->participants($event);
        $context = $this->context($viewer, $event, array_values($users->pluck('id')->map(fn ($id): int => (int) $id)->all()));
        $blocked = $this->blockedIds($viewer);

        $items = [];

        foreach ($users as $user) {
            if ($user->id === $viewer->id) {
                continue;
            }

            if (! $user->share_location || in_array($user->id, $blocked, true)) {
                continue;
            }

            $items[] = $this->item($viewer, $user, $snapshot, $context);
        }

        if (($filters['team'] ?? false) === true) {
            $items = array_values(array_filter($items, fn (array $i): bool => $i['isTeammate']));
        }

        if (($filters['friends'] ?? false) === true) {
            $items = array_values(array_filter($items, fn (array $i): bool => $i['isFriend']));
        }

        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            $items = array_values(array_filter($items, fn (array $i): bool => mb_stripos((string) $i['name'], $q) !== false));
        }

        usort($items, fn (array $a, array $b): int => $this->sortKey($a) <=> $this->sortKey($b));

        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE)));
        $total = count($items);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($lastPage, (int) ($filters['page'] ?? 1)));

        return [
            'me' => $this->item($viewer, $viewer, $snapshot, $context),
            'data' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'lastPage' => $lastPage,
                'snapshotAt' => $snapshot?->builtAt,
            ],
        ];
    }

    /**
     * The targets to follow `$targetId`, or null when there is nobody to follow: an unknown user,
     * a non-participant, somebody who opted out or is blocked, or the viewer themselves. All of
     * those are the same answer on purpose.
     *
     * @return array<string, mixed>|null
     */
    public function follow(User $viewer, Event $event, int $targetId): ?array
    {
        if ($targetId === $viewer->id) {
            return null;
        }

        $target = $this->participants($event)->firstWhere('id', $targetId);

        if (! $target instanceof User || ! $target->share_location || in_array($target->id, $this->blockedIds($viewer), true)) {
            return null;
        }

        $snapshot = $this->snapshot();
        $context = $this->context($viewer, $event, [$viewer->id, $target->id]);
        $location = $snapshot?->people[$target->id] ?? null;
        $mine = $snapshot?->people[$viewer->id] ?? null;

        $connected = array_map(fn (VoiceLocation $v): string => $v->provider, $mine->voice ?? []);

        $voice = [];

        foreach ($location->voice ?? [] as $room) {
            $voice[] = [
                'provider' => $room->provider,
                'roomLabel' => $room->roomLabel,
                'kind' => $room->kind,
                'joinable' => ($join = $this->joinTarget($viewer, $room)) !== null,
                'join' => $join,
                'connected' => in_array($room->provider, $connected, true),
            ];
        }

        // The provider I am already on first, then joinable before not joinable.
        $ordered = [];

        foreach ([[true, true], [true, false], [false, true], [false, false]] as [$wantConnected, $wantJoinable]) {
            foreach ($voice as $row) {
                if ($row['connected'] === $wantConnected && $row['joinable'] === $wantJoinable) {
                    $ordered[] = $row;
                }
            }
        }

        $voice = $ordered;

        $preferred = null;

        foreach ($voice as $row) {
            if ($row['connected'] && $row['joinable']) {
                $preferred = $row['provider'];
                break;
            }
        }

        $game = $location?->game === null ? null : $this->game($location->game, $context['servers']);

        return [
            'user' => ['id' => $target->id, 'name' => $target->name],
            'preferredProvider' => $preferred,
            'voice' => array_map(function (array $row): array {
                unset($row['connected']);

                return $row;
            }, $voice),
            'game' => $game === null ? null : $game + ['copyText' => $game['copyText'] ?? null],
        ];
    }

    public function isParticipant(Event $event, int $userId): bool
    {
        return $this->participants($event)->contains('id', $userId);
    }

    private function snapshot(): ?LocationSnapshot
    {
        $snapshot = $this->store->get();

        if ($snapshot === null) {
            // Cold cache: have it built (inline on a sync queue, in the background otherwise).
            RefreshLocationSnapshotJob::dispatch();
            $snapshot = $this->store->get();
        }

        return $snapshot;
    }

    /**
     * @return Collection<int, User>
     */
    private function participants(Event $event): Collection
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', '!=', RegistrationStatus::Cancelled->value)
            ->with('user')
            ->get()
            ->map(fn (EventRegistration $registration): ?User => $registration->user)
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * @return list<int>
     */
    private function blockedIds(User $viewer): array
    {
        return $this->modules->enabled(Module::Friends) ? $this->friends->blockedUserIdsEitherWay($viewer) : [];
    }

    /**
     * Per-request facts shared by every row: my teams, team labels, my friends, the servers I may see.
     *
     * @param  list<int>  $userIds  the people whose teams matter (the viewer is always added)
     * @return array<string, mixed>
     */
    private function context(User $viewer, Event $event, array $userIds): array
    {
        $memberships = TeamMember::query()
            ->whereIn('user_id', [...$userIds, $viewer->id])
            ->with('team')
            ->get(['team_id', 'user_id']);

        $labels = [];
        $myTeamIds = [];

        foreach ($memberships as $membership) {
            if ($membership->user_id === $viewer->id) {
                $myTeamIds[] = $membership->team_id;
            }

            $labels[$membership->user_id] ??= (string) $membership->team?->name;
        }

        return [
            'teams' => $labels,
            'myTeamIds' => $myTeamIds,
            'teamIdsByUser' => $memberships->groupBy('user_id')->map(fn ($rows): array => $rows->pluck('team_id')->map(fn ($id): int => (int) $id)->all())->all(),
            'friendIds' => $this->modules->enabled(Module::Friends) ? $this->friends->friendUserIds($viewer) : [],
            'servers' => $this->servers->visibleServers($viewer, $event),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function item(User $viewer, User $user, ?LocationSnapshot $snapshot, array $context): array
    {
        $location = $snapshot?->people[$user->id] ?? null;

        /** @var array<int, list<int>> $teamIdsByUser */
        $teamIdsByUser = $context['teamIdsByUser'];
        /** @var list<int> $myTeamIds */
        $myTeamIds = $context['myTeamIds'];
        /** @var array<int, string> $teams */
        $teams = $context['teams'];
        /** @var list<int> $friendIds */
        $friendIds = $context['friendIds'];
        /** @var array<int, array{title: ?string, address: ?string, port: ?int, joinUrl: ?string, copyText: ?string}> $servers */
        $servers = $context['servers'];

        $isMe = $user->id === $viewer->id;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatarUrl' => $user->nativeAvatarSrc(),
            'team' => ($teams[$user->id] ?? '') !== '' ? $teams[$user->id] : null,
            'isMe' => $isMe,
            'isTeammate' => ! $isMe && array_intersect($teamIdsByUser[$user->id] ?? [], $myTeamIds) !== [],
            'isFriend' => in_array($user->id, $friendIds, true),
            'voice' => array_map(fn (VoiceLocation $v): array => $this->voiceRow($viewer, $v), $location->voice ?? []),
            'game' => $location?->game === null ? null : $this->gameRow($this->game($location->game, $servers)),
            'lastSeenAt' => $snapshot?->lastSeen[$user->id] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function voiceRow(User $viewer, VoiceLocation $room): array
    {
        $joinable = $this->mayJoin($viewer, $room);

        return [
            'provider' => $room->provider,
            'roomLabel' => $room->roomLabel,
            'roomRef' => $joinable ? $room->roomRef : null,
            'kind' => $room->kind,
            'since' => $room->since,
        ];
    }

    /** True when the room is open or the viewer is on its roster (orga may always join). */
    private function mayJoin(User $viewer, VoiceLocation $room): bool
    {
        return $room->audience === null || $viewer->isOrga() || in_array($viewer->id, $room->audience, true);
    }

    /**
     * What to hand the viewer to join the room, or null when they may not.
     *
     * @return array<string, mixed>|null
     */
    private function joinTarget(User $viewer, VoiceLocation $room): ?array
    {
        if (! $this->mayJoin($viewer, $room)) {
            return null;
        }

        return match ($room->provider) {
            'webvoice' => ctype_digit($room->roomRef) ? [
                'roomId' => (int) $room->roomRef,
                'tokenPath' => route('voice-client.v1.rooms.token', ['room' => (int) $room->roomRef], false),
            ] : null,
            'discord' => (string) config('services.discord.guild_id') === '' ? null : [
                'url' => 'https://discord.com/channels/'.config('services.discord.guild_id').'/'.$room->roomRef,
            ],
            'mumble', 'teamspeak' => [
                'url' => VoiceJoinLink::for(VoiceProvider::from($room->provider), $room->joinPath ?? $room->roomLabel, $viewer->name),
            ],
            default => null,
        };
    }

    /**
     * The game as this viewer may see it: a server they cannot see loses everything that locates it.
     *
     * @param  array<int, array{title: ?string, address: ?string, port: ?int, joinUrl: ?string, copyText: ?string}>  $servers
     * @return array<string, mixed>
     */
    private function game(GameLocation $game, array $servers): array
    {
        if ($game->linkId !== null) {
            $server = $servers[$game->linkId] ?? null;

            if ($server === null) {
                return $this->gameShape($game, null, null, null, null, null, null, null);
            }

            return $this->gameShape($game, $server['title'] ?? $game->serverTitle, $server['address'] ?? $game->address, $server['port'] ?? $game->port, $game->linkId, $server['joinUrl'], $server['copyText'], $game->since);
        }

        $endpoint = $game->address !== null && $game->port !== null ? $game->address.':'.$game->port : null;

        return $this->gameShape($game, null, $game->address, $game->port, null, $endpoint === null ? null : 'steam://connect/'.$endpoint, $endpoint, $game->since);
    }

    /**
     * @return array<string, mixed>
     */
    private function gameShape(GameLocation $game, ?string $title, ?string $address, ?int $port, ?int $linkId, ?string $joinUrl, ?string $copyText, ?string $since): array
    {
        return [
            'source' => $game->source,
            'game' => $game->game,
            'serverTitle' => $title,
            'address' => $address,
            'port' => $port,
            'joinUrl' => $joinUrl,
            'linkId' => $linkId,
            'since' => $since ?? $game->since,
            'copyText' => $copyText,
        ];
    }

    /**
     * The list shape of a game: everything but the follow-only `copyText`.
     *
     * @param  array<string, mixed>  $game
     * @return array<string, mixed>
     */
    private function gameRow(array $game): array
    {
        unset($game['copyText']);

        return $game;
    }

    /**
     * Teammates first, then people who are in voice or a game, then the rest; by name within a group.
     *
     * @param  array<string, mixed>  $item
     */
    private function sortKey(array $item): string
    {
        $somewhere = $item['voice'] !== [] || $item['game'] !== null;

        return ($item['isTeammate'] ? '0' : '1').($somewhere ? '0' : '1').mb_strtolower((string) $item['name']).'|'.sprintf('%010d', (int) $item['id']);
    }
}
