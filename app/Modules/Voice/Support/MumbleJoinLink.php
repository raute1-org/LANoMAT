<?php

declare(strict_types=1);

namespace App\Modules\Voice\Support;

use App\Modules\Discord\Support\MatchEmbed;
use App\Modules\Voice\Domain\MumbleChannel;

/**
 * Builds a `mumble://` deep link that, when opened by a Mumble client,
 * connects to the configured server and joins the given channel path
 * directly (see the Mumble URL scheme spec). Used to surface a "join voice"
 * action both in the Discord match embed ({@see MatchEmbed})
 * and on the tournament show page.
 */
class MumbleJoinLink
{
    public static function for(MumbleChannel|string $path): string
    {
        $channelPath = $path instanceof MumbleChannel ? $path->name : $path;

        $host = (string) config('services.mumble.host');
        $port = (string) config('services.mumble.port');

        return "mumble://{$host}:{$port}/{$channelPath}";
    }
}
