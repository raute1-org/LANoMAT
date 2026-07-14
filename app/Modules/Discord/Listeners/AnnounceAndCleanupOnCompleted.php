<?php

namespace App\Modules\Discord\Listeners;

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Jobs\CleanupMatchChannelJob;
use App\Modules\Discord\Support\DiscordOutboxGuard;
use App\Modules\Tournaments\Events\MatchCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacts to a decided match by announcing the result into its Discord
 * channel (if one was created — see {@see CreateMatchChannelOnReady}) and
 * scheduling {@see CleanupMatchChannelJob} after a grace period so both
 * rosters have time to read the result before the channel disappears.
 *
 * The announcement is deduplicated via {@see DiscordOutboxGuard} so a
 * re-fired `MatchCompleted` never announces twice; the cleanup dispatch
 * itself is naturally idempotent because the job is a no-op once
 * `discord_channels` has already been cleared.
 */
class AnnounceAndCleanupOnCompleted implements ShouldQueue
{
    public function __construct(
        private readonly DiscordOutboxGuard $guard,
        private readonly DiscordClient $client,
    ) {}

    public function handle(MatchCompleted $event): void
    {
        $match = $event->match->fresh(['entry1', 'entry2', 'winnerEntry']);

        if ($match === null) {
            return;
        }

        $channelId = $match->discord_channels['text_channel_id'] ?? null;

        if ($channelId !== null) {
            $unknownOpponent = __('discord.match_channel.unknown_opponent');
            $entry1Name = $match->entry1?->display_name;
            $entry2Name = $match->entry2?->display_name;
            $winnerName = $match->winnerEntry?->display_name;

            $content = __('discord.match_channel.result_announcement', [
                'entry1' => $entry1Name ?? $unknownOpponent,
                'entry2' => $entry2Name ?? $unknownOpponent,
                'winner' => $winnerName ?? $unknownOpponent,
            ]);

            $this->guard->once(
                "match-{$match->id}-completed",
                'match_result_announced',
                fn () => $this->client->sendMessage($channelId, $content),
                channelId: $channelId,
                content: $content,
            );
        }

        $delayMinutes = (int) config('services.discord.match_channel_cleanup_delay_minutes');

        CleanupMatchChannelJob::dispatch($match->id)->delay(now()->addMinutes($delayMinutes));
    }
}
