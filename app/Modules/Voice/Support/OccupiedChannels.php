<?php

declare(strict_types=1);

namespace App\Modules\Voice\Support;

use App\Modules\Voice\Contracts\VoiceClient;

/**
 * Whether anyone is currently in a set of voice channels or below them.
 *
 * The cleanup jobs use it to leave channels alone until the last person has
 * left: deleting a channel moves its occupants out of the room, and deleting
 * a parent channel takes its sub-channels with it, so a person in a
 * sub-channel counts as being in every channel above it.
 */
final class OccupiedChannels
{
    /** Guards against a parent loop in a misbehaving provider's channel list. */
    private const MAX_DEPTH = 32;

    /**
     * @param  list<int>  $channelIds
     */
    public static function anyOccupied(VoiceClient $client, array $channelIds): bool
    {
        if ($channelIds === []) {
            return false;
        }

        $channels = [];

        foreach ($client->listChannels() as $channel) {
            $channels[$channel->id] = $channel;
        }

        foreach ($channels as $channel) {
            if ($channel->occupants <= 0) {
                continue;
            }

            $current = $channel;

            for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
                if (in_array($current->id, $channelIds, true)) {
                    return true;
                }

                if ($current->parentId === null || ! isset($channels[$current->parentId]) || $current->parentId === $current->id) {
                    break;
                }

                $current = $channels[$current->parentId];
            }
        }

        return false;
    }
}
