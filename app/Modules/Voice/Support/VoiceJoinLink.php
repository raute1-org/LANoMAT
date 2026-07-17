<?php

declare(strict_types=1);

namespace App\Modules\Voice\Support;

use App\Modules\Voice\Domain\VoiceProvider;

/**
 * Builds a provider-aware voice deep link — `mumble://` (see the Mumble URL
 * scheme spec) or `ts3server://` (see the TeamSpeak 3 client URL scheme) —
 * that, when opened by the matching client, connects to the configured
 * server and joins the given channel directly. Supersedes {@see MumbleJoinLink}
 * as the single entry point once a team can choose its voice provider;
 * MumbleJoinLink now delegates here for the Mumble-only case.
 */
class VoiceJoinLink
{
    public static function for(VoiceProvider $provider, string $channelName): string
    {
        return match ($provider) {
            VoiceProvider::Mumble => self::mumbleLink($channelName),
            VoiceProvider::TeamSpeak => self::teamSpeakLink($channelName),
        };
    }

    public static function defaultProviderFor(?VoiceProvider $teamChoice): VoiceProvider
    {
        return $teamChoice ?? VoiceProvider::from((string) config('services.voice.default_provider'));
    }

    private static function mumbleLink(string $channelName): string
    {
        $host = (string) config('services.mumble.host');
        $port = (string) config('services.mumble.port');

        return "mumble://{$host}:{$port}/{$channelName}";
    }

    private static function teamSpeakLink(string $channelName): string
    {
        $host = (string) config('services.teamspeak.host');
        $port = (string) config('services.teamspeak.port');

        return "ts3server://{$host}?port={$port}&channel=".rawurlencode($channelName);
    }
}
