<?php

namespace App\Modules\Discord\Jobs;

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Listeners\AnnounceAndCleanupOnCompleted;
use App\Modules\Tournaments\Models\GameMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tears down a match's Discord text channel a configurable delay after the
 * match completed (see {@see AnnounceAndCleanupOnCompleted}), giving both
 * rosters a grace period to read the result before the channel disappears.
 */
class CleanupMatchChannelJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $matchId,
    ) {}

    public function handle(DiscordClient $client): void
    {
        $match = GameMatch::query()->find($this->matchId);

        $channelId = $match?->discord_channels['text_channel_id'] ?? null;

        if ($match === null || $channelId === null) {
            return;
        }

        $client->deleteChannel($channelId);

        $match->update(['discord_channels' => null]);
    }
}
