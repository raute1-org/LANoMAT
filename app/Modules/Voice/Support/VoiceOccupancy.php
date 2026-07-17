<?php

declare(strict_types=1);

namespace App\Modules\Voice\Support;

use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Voice\Contracts\VoiceClient;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\VoiceProviders;

/**
 * Read-only aggregation of live occupant counts (roadmap issue #13) for the
 * voice channels a tournament/match already has stored ids for
 * (`tournaments.settings['voice']` / `matches.voice_channels`, per
 * {@see ProvisionTournamentVoiceJob}/{@see ProvisionMatchVoiceJob}/
 * ProvisionServerVoiceJob). Calls each active provider's
 * {@see VoiceClient::listChannels()} once and
 * maps the reported `occupants` back onto the stored channel ids — a channel
 * id with no matching entry in `listChannels()` (e.g. deleted, or the
 * provider unreachable) is reported as 0 rather than omitted, so callers can
 * index without an `isset()` check.
 *
 * Real occupant numbers depend on the provider sidecars actually running
 * (mode A — deferred, see the M8 roadmap insights); in dev without the
 * sidecars every count is 0.
 *
 * Pure/IO-bearing only through the injected {@see VoiceProviders}: no writes,
 * no caching — callers needing this on every request should keep the
 * fan-out to the providers they actually have ids for.
 */
final class VoiceOccupancy
{
    /**
     * @return array<string, array<int, int>> provider value => channel id => occupants
     */
    public static function forTournament(Tournament $tournament): array
    {
        $voice = $tournament->settings['voice'] ?? null;

        if ($voice === null) {
            return [];
        }

        $channelIdsByProvider = [];

        foreach ($voice as $value => $subtree) {
            $provider = VoiceProvider::tryFrom((string) $value);

            if ($provider === null) {
                continue;
            }

            $ids = $subtree['team_channel_ids'] ?? [];

            if (isset($subtree['tournament_channel_id'])) {
                $ids[] = $subtree['tournament_channel_id'];
            }

            $channelIdsByProvider[$provider->value] = $ids;
        }

        return self::occupantsFor($channelIdsByProvider);
    }

    /**
     * @return array<string, array<int, int>> provider value => channel id => occupants
     */
    public static function forMatch(GameMatch $match): array
    {
        $voiceChannels = $match->voice_channels;

        if ($voiceChannels === null) {
            return [];
        }

        $channelIdsByProvider = [];

        foreach ($voiceChannels as $value => $subtree) {
            $provider = VoiceProvider::tryFrom((string) $value);

            if ($provider === null) {
                continue;
            }

            $ids = [];

            foreach (['entry1_channel_id', 'entry2_channel_id', 'server_channel_id'] as $key) {
                if (isset($subtree[$key])) {
                    $ids[] = $subtree[$key];
                }
            }

            $channelIdsByProvider[$provider->value] = $ids;
        }

        return self::occupantsFor($channelIdsByProvider);
    }

    /**
     * @param  array<string, array<int, int>>  $channelIdsByProvider  provider value => channel ids
     * @return array<string, array<int, int>> provider value => channel id => occupants
     */
    private static function occupantsFor(array $channelIdsByProvider): array
    {
        if ($channelIdsByProvider === []) {
            return [];
        }

        $providers = app(VoiceProviders::class);
        $result = [];

        foreach ($channelIdsByProvider as $value => $channelIds) {
            $provider = VoiceProvider::tryFrom($value);

            if ($provider === null || $channelIds === []) {
                continue;
            }

            $client = $providers->for($provider);
            $occupantsByChannelId = [];

            foreach ($client->listChannels() as $channel) {
                $occupantsByChannelId[$channel->id] = $channel->occupants;
            }

            $result[$value] = [];

            foreach ($channelIds as $channelId) {
                $result[$value][$channelId] = $occupantsByChannelId[$channelId] ?? 0;
            }
        }

        return $result;
    }
}
