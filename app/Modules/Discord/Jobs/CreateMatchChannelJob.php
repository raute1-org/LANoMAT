<?php

namespace App\Modules\Discord\Jobs;

use App\Enums\Role;
use App\Models\User;
use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Support\DiscordOutboxGuard;
use App\Modules\Discord\Support\EventCategories;
use App\Modules\Discord\Support\MatchEmbed;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Models\GameMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Creates the Discord channels of a match once it becomes playable
 * ({@see MatchReady}), in the category of the match's event
 * ({@see EventCategories}): a private text channel for both rosters with a
 * welcome embed, and a voice room for each team. The rooms are visible to
 * everyone but only that team and the admins can join. The ids are persisted
 * onto `matches.discord_channels` so {@see CleanupMatchChannelJob} can tear
 * them down, or archive the text channel, later.
 *
 * Guarded by {@see DiscordOutboxGuard} so a re-fired `MatchReady` (e.g. a
 * replayed event) never creates a second set of channels for the same match.
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

    public function handle(DiscordClient $client, DiscordOutboxGuard $guard, EventCategories $categories): void
    {
        $match = GameMatch::query()->with(['entry1', 'entry2', 'tournament.event'])->find($this->matchId);

        if ($match === null || $match->entry1 === null || $match->entry2 === null) {
            return;
        }

        $guard->once(
            "match-{$match->id}-created",
            'match_channel_created',
            function () use ($match, $client, $categories): void {
                $guildId = (string) config('services.discord.guild_id');
                $fixedCategory = config('services.discord.match_category_id');

                // A fixed category from the configuration wins; otherwise every event gets a
                // category of its own (one text channel and one room per team are created here).
                $parentValue = is_string($fixedCategory) && $fixedCategory !== ''
                    ? $fixedCategory
                    : ($match->tournament?->event === null ? null : $categories->liveFor($match->tournament->event, 3));

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
                $overwritesFor = fn (string $rosterAllow, Collection $ids): array => [
                    ...($botId === null ? [] : [['id' => $botId, 'type' => 1, 'allow' => '1024', 'deny' => '0']]),
                    ['id' => $guildId, 'type' => 0, 'allow' => '0', 'deny' => '1024'],
                    ...$ids->map(fn (string $discordUserId): array => [
                        'id' => $discordUserId,
                        'type' => 1, // member overwrite
                        'allow' => $rosterAllow,
                        'deny' => '0',
                    ])->all(),
                ];

                // A team's room is visible to everyone, but only its team and the admins may
                // connect: @everyone keeps VIEW_CHANNEL and loses CONNECT.
                $adminIds = User::query()
                    ->where('role', Role::Admin->value)
                    ->whereNotNull('discord_id')
                    ->pluck('discord_id');

                $roomOverwritesFor = fn ($entry): array => [
                    ...($botId === null ? [] : [['id' => $botId, 'type' => 1, 'allow' => '1024', 'deny' => '0']]),
                    ['id' => $guildId, 'type' => 0, 'allow' => (string) self::VIEW_CHANNEL, 'deny' => (string) self::CONNECT],
                    ...array_map(
                        fn (string $discordUserId): array => [
                            'id' => $discordUserId,
                            'type' => 1,
                            'allow' => (string) (self::VIEW_CHANNEL | self::CONNECT | self::SPEAK),
                            'deny' => '0',
                        ],
                        array_values(array_unique([...$entry->rosterDiscordIds(), ...$adminIds->all()])),
                    ),
                ];

                $created = [];
                $voiceChannelIds = [];

                try {
                    $created[] = $channelId;
                    $client->upsertPermissionOverwrites($channelId, $overwritesFor((string) self::VIEW_CHANNEL, $rosterIds));

                    $embed = MatchEmbed::welcome($match);
                    $client->sendMessage($channelId, $embed['title'], [$embed]);

                    foreach (['entry1' => $match->entry1, 'entry2' => $match->entry2] as $key => $entry) {
                        $roomId = $client->createChannel($guildId, "Match {$match->id} · {$entry->display_name}", $parentValue, voice: true);
                        $created[] = $roomId;
                        $voiceChannelIds[$key] = $roomId;
                        $client->upsertPermissionOverwrites($roomId, $roomOverwritesFor($entry));
                    }
                } catch (Throwable $e) {
                    foreach ($created as $id) {
                        $client->deleteChannel($id);
                    }

                    throw $e;
                }

                $match->update(['discord_channels' => ['text_channel_id' => $channelId, 'voice_channel_ids' => $voiceChannelIds]]);
            },
        );
    }
}
