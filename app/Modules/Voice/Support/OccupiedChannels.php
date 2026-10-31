<?php

declare(strict_types=1);

namespace App\Modules\Voice\Support;

use App\Modules\Voice\Contracts\VoiceClient;

/**
 * Which of a set of voice channels must stay because someone is in them.
 *
 * The cleanup jobs use it to remove empty rooms at once while leaving the ones
 * with people in them alone until the last person has left: deleting a channel
 * moves its occupants out of the room, and deleting a parent channel takes its
 * sub-channels with it, so a person in a sub-channel keeps every channel above
 * it alive too.
 */
final class OccupiedChannels
{
    /** Guards against a parent loop in a misbehaving provider's channel list. */
    private const MAX_DEPTH = 32;

    /**
     * The given channels that have someone in them or above someone, in the order given.
     *
     * @param  list<int>  $channelIds
     * @return list<int>
     */
    public static function retained(VoiceClient $client, array $channelIds): array
    {
        if ($channelIds === []) {
            return [];
        }

        $channels = [];

        foreach ($client->listChannels() as $channel) {
            $channels[$channel->id] = $channel;
        }

        $retained = [];

        foreach ($channels as $channel) {
            if ($channel->occupants <= 0) {
                continue;
            }

            $current = $channel;

            for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
                if (in_array($current->id, $channelIds, true)) {
                    $retained[$current->id] = true;
                }

                if ($current->parentId === null || ! isset($channels[$current->parentId]) || $current->parentId === $current->id) {
                    break;
                }

                $current = $channels[$current->parentId];
            }
        }

        return array_values(array_filter($channelIds, fn (int $id): bool => isset($retained[$id])));
    }

    /**
     * @param  list<int>  $channelIds
     */
    public static function anyOccupied(VoiceClient $client, array $channelIds): bool
    {
        return self::retained($client, $channelIds) !== [];
    }
}
