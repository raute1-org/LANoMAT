<?php

namespace App\Modules\Discord\Jobs;

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Support\DiscordOutboxGuard;
use App\Modules\Discord\Support\MatchEmbed;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Models\GameMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Creates the per-match Discord text channel once a match becomes playable
 * ({@see MatchReady}): a text channel under the configured match category,
 * permission overwrites granting both rosters access, and a welcome embed.
 * The channel id is persisted onto `matches.discord_channels` so
 * {@see CleanupMatchChannelJob} can tear it down later.
 *
 * Guarded by {@see DiscordOutboxGuard} so a re-fired `MatchReady` (e.g. a
 * replayed event) never creates a second channel for the same match.
 */
class CreateMatchChannelJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $matchId,
    ) {}

    public function handle(DiscordClient $client, DiscordOutboxGuard $guard): void
    {
        $match = GameMatch::query()->with(['entry1', 'entry2', 'tournament'])->find($this->matchId);

        if ($match === null || $match->entry1 === null || $match->entry2 === null) {
            return;
        }

        $guard->once(
            "match-{$match->id}-created",
            'match_channel_created',
            function () use ($match, $client): void {
                $guildId = (string) config('services.discord.guild_id');
                $parentId = config('services.discord.match_category_id');

                $channelId = $client->createChannel(
                    $guildId,
                    "match-{$match->id}",
                    is_string($parentId) && $parentId !== '' ? $parentId : null,
                );

                $rosterOverwrites = collect([$match->entry1, $match->entry2])
                    ->flatMap(fn ($entry) => $entry->rosterDiscordIds())
                    ->unique()
                    ->map(fn (string $discordUserId): array => [
                        'id' => $discordUserId,
                        'type' => 1, // member overwrite
                        'allow' => '1024', // VIEW_CHANNEL
                        'deny' => '0',
                    ])
                    ->values()
                    ->all();

                // Discord convention: the guild's @everyone role id equals the
                // guild id itself. Member-level `allow` overwrites only grant
                // *additional* access on top of the base role, so without this
                // deny the channel would still be visible to the whole guild.
                $everyoneOverwrite = [
                    'id' => $guildId,
                    'type' => 0, // role overwrite
                    'allow' => '0',
                    'deny' => '1024', // VIEW_CHANNEL
                ];

                $overwrites = [...$rosterOverwrites, $everyoneOverwrite];

                $client->upsertPermissionOverwrites($channelId, $overwrites);

                $embed = MatchEmbed::welcome($match);
                $client->sendMessage($channelId, $embed['title'], [$embed]);

                $match->update(['discord_channels' => ['text_channel_id' => $channelId]]);
            },
        );
    }
}
