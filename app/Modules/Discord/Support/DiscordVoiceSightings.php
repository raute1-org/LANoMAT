<?php

declare(strict_types=1);

namespace App\Modules\Discord\Support;

use App\Modules\Discord\Models\DiscordVoiceState;
use App\Modules\Presence\Contracts\VoiceSightingSource;
use App\Modules\Presence\Domain\VoiceSighting;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Support\EntryRoster;

/**
 * Who is in which Discord voice room, from the Gateway's voice states ({@see DiscordVoiceState}).
 * Only voice states already mapped to a LANoMAT user are used; a Discord account LANoMAT does not
 * know is neither named nor stored anywhere (the No-PII rule of {@see VoicePresenceProjection}).
 * The rooms LANoMAT made for a match carry that match's roster as their audience.
 */
class DiscordVoiceSightings implements VoiceSightingSource
{
    public function provider(): string
    {
        return 'discord';
    }

    public function sightings(): array
    {
        $states = DiscordVoiceState::query()->whereNotNull('user_id')->get();

        if ($states->isEmpty()) {
            return [];
        }

        $matchRooms = $this->matchRooms();
        $sightings = [];

        foreach ($states as $state) {
            $room = $matchRooms[$state->channel_id] ?? null;

            $sightings[] = new VoiceSighting(
                provider: 'discord',
                roomRef: $state->channel_id,
                roomLabel: $state->channel_name ?? 'Discord',
                kind: $room === null ? VoiceSighting::KIND_OTHER : VoiceSighting::KIND_MATCH,
                userId: $state->user_id,
                audience: $room,
            );
        }

        return $sightings;
    }

    /**
     * Channel id => the roster that may join it, for every team room of a match that has one.
     *
     * @return array<string, list<int>>
     */
    private function matchRooms(): array
    {
        $rooms = [];

        $matches = GameMatch::query()->whereNotNull('discord_channels')->with(['entry1', 'entry2'])->get();

        foreach ($matches as $match) {
            foreach (['entry1' => $match->entry1, 'entry2' => $match->entry2] as $key => $entry) {
                $channelId = $match->discord_channels['voice_channel_ids'][$key] ?? null;

                if ($entry !== null && is_string($channelId) && $channelId !== '') {
                    $rooms[$channelId] = array_values(array_map('intval', EntryRoster::userIdsFor($entry)));
                }
            }
        }

        return $rooms;
    }
}
