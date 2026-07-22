<?php

namespace App\Modules\Discord\Support;

use App\Models\User;
use App\Modules\Discord\Events\DiscordVoicePresenceUpdated;
use App\Modules\Discord\Models\DiscordVoiceState;

/**
 * Applies a forwarded VOICE_STATE_UPDATE to the read-model: null channel =
 * left (delete), otherwise join/move (upsert), then broadcasts the change.
 */
class HandleVoiceState
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): void
    {
        $discordUserId = $data['user_id'] ?? null;

        if (! is_string($discordUserId)) {
            return;
        }

        $channelId = $data['channel_id'] ?? null;

        if (! is_string($channelId)) {
            DiscordVoiceState::query()->where('discord_user_id', $discordUserId)->delete();
        } else {
            $userId = User::query()->where('discord_id', $discordUserId)->value('id');

            DiscordVoiceState::query()->updateOrCreate(
                ['discord_user_id' => $discordUserId],
                [
                    'channel_id' => $channelId,
                    'channel_name' => is_string($data['channel_name'] ?? null) ? $data['channel_name'] : null,
                    'user_id' => $userId,
                ],
            );
        }

        DiscordVoicePresenceUpdated::dispatch();
    }
}
