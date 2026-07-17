<?php

declare(strict_types=1);

namespace App\Modules\Voice\Support;

use App\Modules\Discord\Support\MatchEmbed;
use App\Modules\Voice\Domain\VoiceChannel;
use App\Modules\Voice\Domain\VoiceProvider;

/**
 * Builds a `mumble://` deep link that, when opened by a Mumble client,
 * connects to the configured server and joins the given channel path
 * directly (see the Mumble URL scheme spec). Used to surface a "join voice"
 * action both in the Discord match embed ({@see MatchEmbed})
 * and on the tournament show page. Delegates to {@see VoiceJoinLink}, the
 * provider-aware successor, hard-coded to the Mumble provider.
 */
class MumbleJoinLink
{
    public static function for(VoiceChannel|string $path): string
    {
        $channelPath = $path instanceof VoiceChannel ? $path->name : $path;

        return VoiceJoinLink::for(VoiceProvider::Mumble, $channelPath);
    }
}
