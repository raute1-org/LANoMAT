<?php

namespace App\Modules\Discord\Support;

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Models\DiscordEventCategory;
use App\Modules\Events\Models\Event;

/**
 * Finds, or creates, the Discord category a channel of an event belongs in.
 *
 * Every event gets a category of its own for its running match channels and a
 * second one ("Archiv: <event>") for the text channels of finished matches: the
 * Discord server outlives every event, so each LAN stays findable years later.
 * Discord allows 50 channels per category, so when the current one cannot take
 * what is about to be created, a numbered follow-up category is started. A
 * category somebody deleted on the server is created again.
 */
class EventCategories
{
    /** Discord's limit of channels in one category. */
    public const int MAX_CHANNELS = 50;

    /** Discord's limit for a channel or category name. */
    private const int MAX_NAME_LENGTH = 100;

    public function __construct(private readonly DiscordClient $client) {}

    /**
     * The category for the channels of a match that is about to start.
     */
    public function liveFor(Event $event, int $needed): string
    {
        return $this->categoryFor($event, 'live', $needed, '');
    }

    /**
     * The category for a finished match's text channel.
     */
    public function archiveFor(Event $event, int $needed = 1): string
    {
        return $this->categoryFor($event, 'archive', $needed, 'Archiv: ');
    }

    private function categoryFor(Event $event, string $kind, int $needed, string $prefix): string
    {
        $guildId = (string) config('services.discord.guild_id');
        $channels = $this->client->listChannels($guildId);

        $present = [];
        $used = [];

        foreach ($channels as $channel) {
            $present[$channel['id']] = true;

            if ($channel['parentId'] !== null) {
                $used[$channel['parentId']] = ($used[$channel['parentId']] ?? 0) + 1;
            }
        }

        $current = DiscordEventCategory::query()
            ->where('event_id', $event->id)
            ->where('kind', $kind)
            ->orderByDesc('sequence')
            ->first();

        if ($current !== null && isset($present[$current->category_id])) {
            if (self::MAX_CHANNELS - ($used[$current->category_id] ?? 0) >= $needed) {
                return $current->category_id;
            }

            return $this->create($event, $kind, $current->sequence + 1, $prefix, $guildId);
        }

        // Nothing yet, or the category was deleted on the server: (re)create the same slot.
        return $this->create($event, $kind, $current->sequence ?? 1, $prefix, $guildId);
    }

    private function create(Event $event, string $kind, int $sequence, string $prefix, string $guildId): string
    {
        $suffix = $sequence > 1 ? " ({$sequence})" : '';
        $name = mb_substr($prefix.$event->name, 0, self::MAX_NAME_LENGTH - mb_strlen($suffix)).$suffix;

        $categoryId = $this->client->createCategory($guildId, $name);

        DiscordEventCategory::query()->updateOrCreate(
            ['event_id' => $event->id, 'kind' => $kind, 'sequence' => $sequence],
            ['category_id' => $categoryId],
        );

        return $categoryId;
    }
}
