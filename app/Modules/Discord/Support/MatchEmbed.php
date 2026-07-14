<?php

namespace App\Modules\Discord\Support;

use App\Modules\Tournaments\Models\GameMatch;

/**
 * Builds the Discord embed payload sent into a freshly created match
 * channel. Carries the match deep-link and both opponent names.
 *
 * The voice-channel join link (Mumble) is a later task (21) — the Mumble
 * client does not exist yet, so it is intentionally omitted here rather than
 * invented. Once Task 21 lands, its link can be appended to the description.
 */
class MatchEmbed
{
    /**
     * @return array<string, mixed>
     */
    public static function welcome(GameMatch $match): array
    {
        $unknownOpponent = __('discord.match_channel.unknown_opponent');
        $entry1DisplayName = $match->entry1?->display_name;
        $entry2DisplayName = $match->entry2?->display_name;
        $entry1Name = $entry1DisplayName ?? $unknownOpponent;
        $entry2Name = $entry2DisplayName ?? $unknownOpponent;

        $url = route('tournaments.show', $match->tournament);

        return [
            'title' => __('discord.match_channel.welcome_title', ['entry1' => $entry1Name, 'entry2' => $entry2Name]),
            // TODO(Task 21): append the Mumble voice-channel join link once
            // the Mumble client contract exists.
            'description' => __('discord.match_channel.welcome_description', [
                'entry1' => $entry1Name,
                'entry2' => $entry2Name,
                'url' => $url,
            ]),
        ];
    }
}
