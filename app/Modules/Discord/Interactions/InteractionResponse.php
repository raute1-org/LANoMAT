<?php

namespace App\Modules\Discord\Interactions;

use App\Modules\Discord\Jobs\SendFollowupJob;

/**
 * Small builders for the two interaction response shapes command handlers
 * return: an immediate message (type 4) and a deferred acknowledgement
 * (type 5, followed up later by {@see SendFollowupJob}).
 */
class InteractionResponse
{
    /**
     * @return array<string, mixed>
     */
    public static function message(string $content): array
    {
        return [
            'type' => InteractionResponseType::ChannelMessageWithSource->value,
            'data' => [
                'content' => $content,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function deferred(): array
    {
        return [
            'type' => InteractionResponseType::DeferredChannelMessageWithSource->value,
        ];
    }
}
