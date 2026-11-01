<?php

declare(strict_types=1);

namespace App\Modules\Voice\Support;

use App\Modules\Presence\Contracts\VoiceSightingSource;
use App\Modules\Presence\Domain\VoiceSighting;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Support\EntryRoster;
use App\Modules\Voice\Domain\VoiceChannel;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\VoiceProviders;

/**
 * Who is in which Mumble or TeamSpeak room. The servers know people only by the name they
 * connected with, so every sighting carries that name and nothing else; the resolver turns it
 * into a LANoMAT user only when exactly one user can have that name (see LocationResolver). One
 * instance per provider; the occupants come from the provider sidecar's channel list.
 *
 * Rooms LANoMAT made for a match (and a team's standing room of a team tournament) carry that
 * roster as their audience; every other room is open.
 */
class VoiceServerSightings implements VoiceSightingSource
{
    public function __construct(
        private readonly VoiceProvider $provider,
        private readonly VoiceProviders $providers,
    ) {}

    public function provider(): string
    {
        return $this->provider->value;
    }

    public function sightings(): array
    {
        if (! in_array($this->provider, VoiceProvider::active(), true)) {
            return [];
        }

        $channels = [];

        foreach ($this->providers->for($this->provider)->listChannels() as $channel) {
            $channels[$channel->id] = $channel;
        }

        $occupied = array_filter($channels, fn (VoiceChannel $c): bool => $c->occupantNames !== []);

        if ($occupied === []) {
            return [];
        }

        $known = $this->knownRooms($channels);
        $sightings = [];

        foreach ($occupied as $channel) {
            [$kind, $audience] = $known[$channel->id] ?? [VoiceSighting::KIND_OTHER, null];

            foreach ($channel->occupantNames as $name) {
                $sightings[] = new VoiceSighting(
                    provider: $this->provider->value,
                    roomRef: (string) $channel->id,
                    roomLabel: $channel->name,
                    kind: $kind,
                    name: $name,
                    audience: $audience,
                    joinPath: $this->path($channel, $channels),
                );
            }
        }

        return $sightings;
    }

    /**
     * A Mumble link names the channel by its path from the root; TeamSpeak finds it by name.
     *
     * @param  array<int, VoiceChannel>  $channels
     */
    private function path(VoiceChannel $channel, array $channels): string
    {
        if ($this->provider !== VoiceProvider::Mumble) {
            return $channel->name;
        }

        $names = [$channel->name];
        $current = $channel;

        for ($depth = 0; $depth < 16 && $current->parentId !== null && isset($channels[$current->parentId]); $depth++) {
            $current = $channels[$current->parentId];
            array_unshift($names, $current->name);
        }

        return implode('/', $names);
    }

    /**
     * Channel id => [kind, audience] for the rooms LANoMAT made on this provider.
     *
     * @param  array<int, VoiceChannel>  $allChannels
     * @return array<int, array{0: string, 1: list<int>|null}>
     */
    private function knownRooms(array $allChannels): array
    {
        $rooms = [];
        $key = $this->provider->value;

        $matches = GameMatch::query()->whereNotNull('voice_channels')->with(['entry1', 'entry2'])->get();

        foreach ($matches as $match) {
            $stored = $match->voice_channels[$key] ?? null;

            if (! is_array($stored)) {
                continue;
            }

            $both = [];

            foreach (['entry1' => $match->entry1, 'entry2' => $match->entry2] as $side => $entry) {
                if ($entry === null) {
                    continue;
                }

                $roster = array_values(array_map('intval', EntryRoster::userIdsFor($entry)));
                $both = [...$both, ...$roster];

                if (isset($stored[$side.'_channel_id'])) {
                    $rooms[(int) $stored[$side.'_channel_id']] = [VoiceSighting::KIND_MATCH, $roster];
                }
            }

            // The match's shared server room belongs to both rosters.
            if (isset($stored['server_channel_id'])) {
                $rooms[(int) $stored['server_channel_id']] = [VoiceSighting::KIND_MATCH, array_values(array_unique($both))];
            }
        }

        $tournaments = Tournament::query()->whereNotNull('settings->voice')->with('entries')->get();

        foreach ($tournaments as $tournament) {
            $stored = $tournament->settings['voice'][$key] ?? null;

            if (! is_array($stored)) {
                continue;
            }

            if (isset($stored['tournament_channel_id'])) {
                $rooms[(int) $stored['tournament_channel_id']] = [VoiceSighting::KIND_GENERAL, null];
            }

            // A team room is named after its entry; a room whose entry cannot be found is closed to all.
            $byName = [];

            foreach ($tournament->entries as $entry) {
                $byName[$entry->display_name] = array_values(array_map('intval', EntryRoster::userIdsFor($entry)));
            }

            foreach ($allChannels as $channel) {
                if (in_array($channel->id, array_map('intval', $stored['team_channel_ids'] ?? []), true)) {
                    $rooms[$channel->id] = [VoiceSighting::KIND_TEAM, $byName[$channel->name] ?? []];
                }
            }
        }

        return $rooms;
    }
}
