<?php

declare(strict_types=1);

namespace App\Modules\Presence\Support;

use App\Models\User;
use App\Modules\Identity\Contracts\SteamPresence;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Support\SteamActivity;
use App\Modules\Presence\Contracts\GameServerDirectory;
use App\Modules\Presence\Domain\GameLocation;
use App\Modules\Presence\Domain\PersonLocation;
use App\Modules\Presence\Domain\ServerRoster;
use App\Modules\Presence\Domain\VoiceLocation;
use App\Modules\Presence\Domain\VoiceSighting;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\Support\VoiceJoinLink;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Throwable;

/**
 * Pulls every source once and writes the {@see LocationSnapshot}: the IO side of the location
 * read model ({@see LocationResolver} is its pure side). Runs from the scheduler, never inside a
 * request. A source that fails contributes nothing ("unknown"), it never fails the refresh.
 *
 * Everybody is located, including people who switched `share_location` off: they must still see
 * their own location. Whom the others may see is decided when the snapshot is read
 * ({@see PeopleDirectory}), against the live setting, so an opt-out takes effect at once. The
 * snapshot lives in the server's cache only and is never sent anywhere as a whole.
 */
final class LocationSnapshotBuilder
{
    /** How long somebody's "last seen" is remembered once they are nowhere. */
    private const int FORGET_LAST_SEEN_AFTER_HOURS = 12;

    public function __construct(
        private readonly SteamPresence $steam,
        private readonly GameServerDirectory $servers,
        private readonly LocationSnapshotStore $store,
        private readonly Modules $modules,
        private readonly VoiceSources $voiceSources,
    ) {}

    public function refresh(): LocationSnapshot
    {
        $previous = $this->store->get();
        $now = now()->toIso8601String();

        /** @var array<int, array{name: string}> $users */
        $users = [];

        foreach (User::query()->select(['id', 'name'])->get() as $user) {
            $users[$user->id] = ['name' => $user->name];
        }

        $resolved = LocationResolver::resolve(
            $this->sightings(),
            $this->voiceNameIndex($users),
            $this->steamActivities(),
            $this->rosters(),
            $this->gameNameIndex($users),
            $now,
        );

        $people = [];

        foreach ($resolved as $userId => $person) {
            $people[$userId] = $this->keepSince($person, $previous?->people[$userId] ?? null);
        }

        $cutoff = now()->subHours(self::FORGET_LAST_SEEN_AFTER_HOURS)->toIso8601String();
        $lastSeen = array_filter($previous->lastSeen ?? [], fn (string $at): bool => $at >= $cutoff);

        foreach (array_keys($people) as $userId) {
            $lastSeen[$userId] = $now;
        }

        $snapshot = new LocationSnapshot($now, $people, $lastSeen);
        $this->store->put($snapshot);

        return $snapshot;
    }

    /**
     * @return list<VoiceSighting>
     */
    private function sightings(): array
    {
        $all = [];

        foreach ($this->voiceSources->all() as $source) {
            try {
                array_push($all, ...$source->sightings());
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $all;
    }

    /**
     * The name each user would carry on each name-based voice provider, the way the join link
     * writes it.
     *
     * @param  array<int, array{name: string}>  $users
     * @return array<string, array<string, list<int>>>
     */
    private function voiceNameIndex(array $users): array
    {
        if (! $this->modules->enabled(Module::Voice)) {
            return [];
        }

        $index = [];

        foreach (VoiceProvider::cases() as $provider) {
            foreach ($users as $id => $user) {
                $name = VoiceJoinLink::serverNameFor($provider, $user['name']);

                if ($name !== null) {
                    $index[$provider->value][LocationResolver::normalize($name)][] = $id;
                }
            }
        }

        return $index;
    }

    /**
     * Display names and linked Steam persona names, for everybody (ambiguity must see all).
     *
     * @param  array<int, array{name: string}>  $users
     * @return array<string, list<int>>
     */
    private function gameNameIndex(array $users): array
    {
        $index = [];

        foreach ($users as $id => $user) {
            $index[LocationResolver::normalize($user['name'])][] = $id;
        }

        $nicknames = LinkedAccount::query()
            ->where('provider', LinkedAccountProvider::Steam->value)
            ->whereNotNull('nickname')
            ->get(['user_id', 'nickname']);

        foreach ($nicknames as $account) {
            $key = LocationResolver::normalize((string) $account->nickname);

            if (! in_array($account->user_id, $index[$key] ?? [], true)) {
                $index[$key][] = $account->user_id;
            }
        }

        return $index;
    }

    /**
     * @return array<int, SteamActivity>
     */
    private function steamActivities(): array
    {
        $accounts = LinkedAccount::query()
            ->where('provider', LinkedAccountProvider::Steam->value)
            ->get(['user_id', 'provider_user_id']);

        if ($accounts->isEmpty()) {
            return [];
        }

        try {
            $activities = $this->steam->activities(array_values($accounts->pluck('provider_user_id')->map(fn ($id): string => (string) $id)->all()));
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $out = [];

        foreach ($accounts as $account) {
            $activity = $activities[(string) $account->provider_user_id] ?? null;

            if ($activity !== null) {
                $out[$account->user_id] = $activity;
            }
        }

        return $out;
    }

    /**
     * @return list<ServerRoster>
     */
    private function rosters(): array
    {
        try {
            return $this->servers->rosters();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** Carries "since" over for every room and game the person was already in at the last refresh. */
    private function keepSince(PersonLocation $person, ?PersonLocation $before): PersonLocation
    {
        if ($before === null) {
            return $person;
        }

        $earlier = [];

        foreach ($before->voice as $voice) {
            $earlier[$voice->key()] = $voice->since;
        }

        $voice = array_map(
            fn (VoiceLocation $v): VoiceLocation => isset($earlier[$v->key()])
                ? new VoiceLocation($v->provider, $v->roomLabel, $v->roomRef, $v->kind, $v->audience, $v->joinPath, $earlier[$v->key()])
                : $v,
            $person->voice,
        );

        $game = $person->game;

        if ($game instanceof GameLocation && $before->game instanceof GameLocation && $before->game->key() === $game->key()) {
            $game = $game->withSince($before->game->since);
        }

        return new PersonLocation($person->userId, $voice, $game);
    }
}
