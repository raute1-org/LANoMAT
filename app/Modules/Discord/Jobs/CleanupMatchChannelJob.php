<?php

namespace App\Modules\Discord\Jobs;

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Listeners\AnnounceAndCleanupOnCompleted;
use App\Modules\Discord\Models\DiscordVoiceState;
use App\Modules\Discord\Support\EventCategories;
use App\Modules\Tournaments\Models\GameMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Winds down a match's Discord channels a configurable delay after the match
 * completed (see {@see AnnounceAndCleanupOnCompleted}), giving both rosters a
 * grace period to read the result first.
 *
 * - The team rooms: an empty one is deleted at once. One with someone in it is
 *   left alone, so nobody is thrown out of their conversation after the match,
 *   and checked again every {@see self::RECHECK_AFTER_MINUTES} minutes until the
 *   last person has left (who is in a room comes from the Gateway's voice
 *   states).
 * - The text channel is not deleted: it moves into the archive category of the
 *   match's event and becomes read-only, so the history of a LAN stays
 *   readable on a server that lives for years.
 *
 * The stored ids are updated as each part is done, which keeps the job
 * idempotent: a repeated run only deals with what is left.
 */
class CleanupMatchChannelJob implements ShouldQueue
{
    use Queueable;

    /** How long to wait before looking again while someone is still in a room. */
    public const RECHECK_AFTER_MINUTES = 2;

    // Discord permission bits.
    private const int VIEW_CHANNEL = 1 << 10;

    private const int ADD_REACTIONS = 1 << 6;

    private const int SEND_MESSAGES = 1 << 11;

    private const int READ_MESSAGE_HISTORY = 1 << 16;

    private const int CREATE_PUBLIC_THREADS = 1 << 35;

    private const int CREATE_PRIVATE_THREADS = 1 << 36;

    private const int SEND_MESSAGES_IN_THREADS = 1 << 38;

    public function __construct(
        public readonly int $matchId,
    ) {}

    public function handle(DiscordClient $client, EventCategories $categories): void
    {
        $match = GameMatch::query()->with('tournament.event')->find($this->matchId);
        $channels = $match?->discord_channels;

        if ($match === null || $channels === null) {
            return;
        }

        $textChannelId = $channels['text_channel_id'] ?? null;

        // Rooms made before every team had its own stored a single `voice_channel_id`.
        $rooms = $channels['voice_channel_ids'] ?? [];
        $legacyRoom = $channels['voice_channel_id'] ?? null;

        if (is_string($legacyRoom) && $legacyRoom !== '') {
            $rooms['legacy'] = $legacyRoom;
        }

        $remaining = [];

        foreach ($rooms as $key => $roomId) {
            if (DiscordVoiceState::query()->where('channel_id', $roomId)->exists()) {
                $remaining[$key] = $roomId;

                continue;
            }

            $client->deleteChannel($roomId);
        }

        $guildId = (string) config('services.discord.guild_id');

        if (is_string($textChannelId) && $textChannelId !== '') {
            // Save the progress first: the rooms are gone, the text channel is next.
            $match->update(['discord_channels' => $this->stored($textChannelId, $remaining)]);

            $this->archive($client, $categories, $match, $textChannelId, $guildId);
        }

        $match->update(['discord_channels' => $this->stored(null, $remaining)]);

        // A sync queue runs a delayed job at once and would loop while someone stays.
        if ($remaining !== [] && config('queue.default') !== 'sync') {
            self::dispatch($match->id)->delay(now()->addMinutes(self::RECHECK_AFTER_MINUTES));
        }
    }

    /**
     * @param  array<string, string>  $rooms
     * @return array<string, mixed>|null
     */
    private function stored(?string $textChannelId, array $rooms): ?array
    {
        $stored = [];

        if ($textChannelId !== null) {
            $stored['text_channel_id'] = $textChannelId;
        }

        if ($rooms !== []) {
            $stored['voice_channel_ids'] = $rooms;
        }

        return $stored === [] ? null : $stored;
    }

    private function archive(DiscordClient $client, EventCategories $categories, GameMatch $match, string $textChannelId, string $guildId): void
    {
        $event = $match->tournament?->event;

        if ($event === null) {
            // Nothing to file the channel under: it goes, as match channels always did.
            $client->deleteChannel($textChannelId);

            return;
        }

        $archiveId = $categories->archiveFor($event);

        $client->moveChannel($textChannelId, $archiveId);

        // Everyone may read the history, nobody may write. A member overwrite that made the
        // channel visible to a roster stays; it only ever granted VIEW_CHANNEL.
        $client->upsertPermissionOverwrites($textChannelId, [[
            'id' => $guildId,
            'type' => 0, // role overwrite: @everyone, whose id is the guild id
            'allow' => (string) (self::VIEW_CHANNEL | self::READ_MESSAGE_HISTORY),
            'deny' => (string) (self::SEND_MESSAGES | self::ADD_REACTIONS | self::CREATE_PUBLIC_THREADS | self::CREATE_PRIVATE_THREADS | self::SEND_MESSAGES_IN_THREADS),
        ]]);
    }
}
