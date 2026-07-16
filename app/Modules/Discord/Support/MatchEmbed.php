<?php

namespace App\Modules\Discord\Support;

use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\PelicanJoinLink;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Voice\Jobs\ProvisionMatchVoiceJob;
use App\Modules\Voice\Support\MumbleJoinLink;

/**
 * Builds the Discord embed payload sent into a freshly created match
 * channel. Carries the match deep-link, both opponent names, and — once
 * {@see ProvisionMatchVoiceJob} has provisioned the
 * match's per-team Mumble channels (`matches.voice_channels`) — a Mumble
 * join link for each roster's own channel, plus — once the match's
 * {@see ServerLink} is Ready — a game
 * server join section ({@see UpdateMatchSurfacesOnServerReady}).
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

        $description = __('discord.match_channel.welcome_description', [
            'entry1' => $entry1Name,
            'entry2' => $entry2Name,
            'url' => $url,
        ]);

        $voiceLink = self::voiceLink($match, $entry1Name, $entry2Name);

        if ($voiceLink !== null) {
            $description .= "\n".$voiceLink;
        }

        $serverLink = self::serverLink($match);

        if ($serverLink !== null) {
            $description .= "\n".$serverLink;
        }

        return [
            'title' => __('discord.match_channel.welcome_title', ['entry1' => $entry1Name, 'entry2' => $entry2Name]),
            'description' => $description,
        ];
    }

    /**
     * Public so other bell/notification surfaces (e.g. MatchReadyBell) can
     * reuse the exact same voice-link text without re-deriving it from the
     * rendered embed description.
     */
    public static function voiceLink(GameMatch $match, string $entry1Name, string $entry2Name): ?string
    {
        $entry1ChannelId = $match->voice_channels['entry1_channel_id'] ?? null;
        $entry2ChannelId = $match->voice_channels['entry2_channel_id'] ?? null;

        if ($entry1ChannelId === null && $entry2ChannelId === null) {
            return null;
        }

        $lines = [];

        if ($entry1ChannelId !== null) {
            $lines[] = "{$entry1Name}: ".MumbleJoinLink::for($entry1Name);
        }

        if ($entry2ChannelId !== null) {
            $lines[] = "{$entry2Name}: ".MumbleJoinLink::for($entry2Name);
        }

        return __('discord.match_channel.voice_links_heading')."\n".implode("\n", $lines);
    }

    /**
     * Public for the same reason as {@see voiceLink()}: so
     * `App\Modules\GameServers\Listeners\UpdateMatchSurfacesOnServerReady`
     * can re-derive the exact same server-join text when (re)posting the
     * embed after the match's {@see ServerLink} turns Ready, without
     * duplicating the join-link formatting.
     */
    public static function serverLink(GameMatch $match): ?string
    {
        $link = $match->serverLink;

        if ($link === null || $link->status !== ServerLinkStatus::Ready) {
            return null;
        }

        $joinLink = PelicanJoinLink::for($link->join_info);

        if ($joinLink === '') {
            return null;
        }

        return __('discord.match_channel.server_link_heading')."\n".$joinLink;
    }
}
