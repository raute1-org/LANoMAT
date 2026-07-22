<?php

declare(strict_types=1);

namespace App\Modules\Discord\Support;

use App\Modules\Discord\Models\DiscordVoiceState;
use App\Modules\Presence\Support\PresenceProjection;

/**
 * Pure, No-PII projection of current Discord voice occupancy: per channel a
 * head count and the display names of *mapped* LANoMAT users only (unmapped
 * Discord users are counted, never named). Mirrors
 * {@see PresenceProjection} discipline; one
 * bounded query.
 */
final class VoicePresenceProjection
{
    /**
     * @return list<array{channel: string, count: int, names: list<string>}>
     */
    public static function current(): array
    {
        $rows = DiscordVoiceState::query()->with('user')->get();

        $byChannel = $rows
            ->groupBy(fn (DiscordVoiceState $s): string => $s->channel_name ?? $s->channel_id)
            ->map(function ($group, $channel): array {
                /** @var list<string> $names */
                $names = array_values(
                    $group
                        ->map(fn (DiscordVoiceState $s): ?string => $s->user?->name)
                        ->filter()
                        ->all()
                );

                return [
                    'channel' => (string) $channel,
                    'count' => (int) $group->count(),
                    'names' => $names,
                ];
            })
            ->all();

        return array_values($byChannel);
    }
}
