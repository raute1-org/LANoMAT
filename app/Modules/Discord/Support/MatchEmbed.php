<?php

declare(strict_types=1);

namespace App\Modules\Discord\Support;

use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\PelicanJoinLink;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\Jobs\ProvisionMatchVoiceJob;
use App\Modules\Voice\Support\VoiceJoinLink;

/**
 * Builds the Discord embed payload sent into a freshly created match
 * channel. Carries the match deep-link, both opponent names, and — once
 * {@see ProvisionMatchVoiceJob} has provisioned the
 * match's per-team voice channels (`matches.voice_channels`, keyed per
 * provider) — a join link per active provider for each roster's own channel,
 * marking the installation/team default, plus — once the match's
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
     *
     * Lists one line per active provider present in `matches.voice_channels`
     * with a channel id for either entry, prefixing the installation's
     * default provider's lines with a text marker (there is no single
     * "viewer" in a shared channel embed, so the config default — not a
     * per-team choice — is what gets highlighted here).
     */
    public static function voiceLink(GameMatch $match, string $entry1Name, string $entry2Name): ?string
    {
        $voiceChannels = $match->voice_channels ?? [];
        $defaultProvider = VoiceJoinLink::defaultProviderFor(null);

        $lines = [];

        foreach ($voiceChannels as $providerValue => $providerChannels) {
            $provider = VoiceProvider::tryFrom((string) $providerValue);

            if ($provider === null) {
                continue;
            }

            $entry1ChannelId = $providerChannels['entry1_channel_id'] ?? null;
            $entry2ChannelId = $providerChannels['entry2_channel_id'] ?? null;

            if ($entry1ChannelId === null && $entry2ChannelId === null) {
                continue;
            }

            $label = $provider === $defaultProvider
                ? __('discord.match_channel.voice_default_marker', ['provider' => $provider->label()])
                : $provider->label();

            $providerLines = ["**{$label}**"];

            if ($entry1ChannelId !== null) {
                $providerLines[] = "{$entry1Name}: ".VoiceJoinLink::for($provider, $entry1Name);
            }

            if ($entry2ChannelId !== null) {
                $providerLines[] = "{$entry2Name}: ".VoiceJoinLink::for($provider, $entry2Name);
            }

            $lines[] = implode("\n", $providerLines);
        }

        if ($lines === []) {
            return null;
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
