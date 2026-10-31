<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Actions;

use App\Modules\WebVoice\Contracts\WebVoiceClient;
use App\Modules\WebVoice\Domain\WebVoiceUnavailable;
use App\Modules\WebVoice\Events\ChannelsChanged;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Support\BroadcastsSafely;

/**
 * Archives channels whose purpose is over. Empty rooms go at once; a room with someone in it
 * stays so nobody is thrown out of a conversation. If the SFU cannot be asked, the room counts as
 * occupied: removing a room under people on a guess is the worse mistake.
 */
class CloseChannels
{
    use BroadcastsSafely;

    public function __construct(private readonly WebVoiceClient $client) {}

    /**
     * @param  array<int, int>  $channelIds
     * @return list<int> the ids that are still occupied (or could not be checked), to look at again later
     */
    public function handle(array $channelIds): array
    {
        $waiting = [];
        $archived = 0;

        $channels = WebVoiceChannel::query()->whereIn('id', $channelIds)->whereNull('archived_at')->get();

        foreach ($channels as $channel) {
            try {
                $occupied = $this->client->participants($channel->roomName()) !== [];
            } catch (WebVoiceUnavailable) {
                $occupied = true;
            }

            if ($occupied) {
                $waiting[] = $channel->getKey();

                continue;
            }

            $channel->forceFill(['archived_at' => now()])->save();
            $archived++;
        }

        if ($archived > 0) {
            $this->broadcastSafely(new ChannelsChanged);
        }

        return $waiting;
    }
}
