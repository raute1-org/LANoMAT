<?php

namespace App\Modules\Discord\Jobs;

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Support\DiscordOutboxGuard;
use App\Modules\Discord\Support\MatchEmbed;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Models\GameMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

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

    // Discord permission bits (VIEW_CHANNEL, CONNECT, SPEAK).
    private const int VIEW_CHANNEL = 1 << 10;

    private const int CONNECT = 1 << 20;

    private const int SPEAK = 1 << 21;

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

                $parentValue = is_string($parentId) && $parentId !== '' ? $parentId : null;

                $channelId = $client->createChannel($guildId, "match-{$match->id}", $parentValue);

                $rosterIds = collect([$match->entry1, $match->entry2])
                    ->flatMap(fn ($entry) => $entry->rosterDiscordIds())
                    ->unique()
                    ->values();

                // The bot must keep its own access: once @everyone is denied the
                // bot loses the channel too (403 Missing Access on the next call),
                // so its allow goes first. The bot's user id is its application id.
                $botId = config('services.discord.application_id') ?: config('services.discord.client_id');
                $botId = is_string($botId) && $botId !== '' ? $botId : null;

                // Discord convention: the guild's @everyone role id equals the
                // guild id itself. Member-level `allow` overwrites only grant
                // *additional* access on top of the base role, so without this
                // deny the channel would still be visible to the whole guild.
                // @everyone comes right after the bot: the channel is private
                // before anything else can fail.
                $overwritesFor = fn (string $rosterAllow): array => [
                    ...($botId === null ? [] : [['id' => $botId, 'type' => 1, 'allow' => '1024', 'deny' => '0']]),
                    ['id' => $guildId, 'type' => 0, 'allow' => '0', 'deny' => '1024'],
                    ...$rosterIds->map(fn (string $discordUserId): array => [
                        'id' => $discordUserId,
                        'type' => 1, // member overwrite
                        'allow' => $rosterAllow,
                        'deny' => '0',
                    ])->all(),
                ];

                $voiceChannelId = null;

                try {
                    $client->upsertPermissionOverwrites($channelId, $overwritesFor((string) self::VIEW_CHANNEL));

                    $embed = MatchEmbed::welcome($match);
                    $client->sendMessage($channelId, $embed['title'], [$embed]);

                    // A private voice channel next to the text one, same roster.
                    $voiceChannelId = $client->createChannel($guildId, "Match {$match->id}", $parentValue, voice: true);
                    $client->upsertPermissionOverwrites($voiceChannelId, $overwritesFor((string) (self::VIEW_CHANNEL | self::CONNECT | self::SPEAK)));
                } catch (Throwable $e) {
                    $client->deleteChannel($channelId);

                    if ($voiceChannelId !== null) {
                        $client->deleteChannel($voiceChannelId);
                    }

                    throw $e;
                }

                $match->update(['discord_channels' => ['text_channel_id' => $channelId, 'voice_channel_id' => $voiceChannelId]]);
            },
        );
    }
}
