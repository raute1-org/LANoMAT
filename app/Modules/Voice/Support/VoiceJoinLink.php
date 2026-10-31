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
    /**
     * `$username` is the name the person should join with (the name LANoMAT knows from
     * Discord), so they do not have to type one. Leave it out for a link that is shared with
     * several people, like the one in a Discord message.
     */
    public static function for(VoiceProvider $provider, string $channelName, ?string $username = null): string
    {
        return match ($provider) {
            VoiceProvider::Mumble => self::mumbleLink($channelName, $username),
            VoiceProvider::TeamSpeak => self::teamSpeakLink($channelName, $username),
        };
    }

    public static function defaultProviderFor(?VoiceProvider $teamChoice): VoiceProvider
    {
        return $teamChoice ?? VoiceProvider::from((string) config('services.voice.default_provider'));
    }

    private static function mumbleLink(string $channelName, ?string $username): string
    {
        $host = (string) config('services.mumble.host');
        $port = (string) config('services.mumble.port');
        $name = self::cleanName($username, 1);

        // mumble://[username@]host[:port]/channel — the client fills the name in itself.
        $auth = $name === null ? '' : rawurlencode($name).'@';

        return "mumble://{$auth}{$host}:{$port}/{$channelName}";
    }

    private static function teamSpeakLink(string $channelName, ?string $username): string
    {
        $host = (string) config('services.teamspeak.host');
        $port = (string) config('services.teamspeak.port');
        $name = self::cleanName($username, 3); // TeamSpeak nicknames need at least 3 characters

        $nickname = $name === null ? '' : '&nickname='.rawurlencode($name);

        return "ts3server://{$host}?port={$port}{$nickname}&channel=".rawurlencode($channelName);
    }

    /**
     * A name the voice servers accept: letters (umlauts included), digits, spaces and a few
     * separators, cut to 30 characters (TeamSpeak's limit). Anything else, such as markup or
     * URL characters, is dropped. Null when nothing usable is left.
     */
    private static function cleanName(?string $username, int $minLength): ?string
    {
        if ($username === null) {
            return null;
        }

        $name = trim((string) preg_replace('/[^\p{L}\p{N} _.@|()\[\]-]/u', '', $username));
        $name = trim(mb_substr($name, 0, 30));

        return mb_strlen($name) >= $minLength ? $name : null;
    }
}
