<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Listeners;

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Listeners\AnnounceAndCleanupOnCompleted;
use App\Modules\Discord\Support\DiscordOutboxGuard;
use App\Modules\Discord\Support\MatchEmbed;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Support\BracketMatchProjection;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacts to a {@see ServerLinkUpdated} for a match-scoped
 * `App\Modules\GameServers\Models\ServerLink` turning Ready by (re)posting
 * the match's Discord embed — now including a server-join section (see
 * {@see MatchEmbed::serverLink()}) — into its existing match channel.
 *
 * The tournament/match page itself needs no push here: it reads the
 * server's join info straight off `GameMatch::serverLink` on next load (see
 * {@see BracketMatchProjection}), so this listener's only job is the
 * Discord surface.
 *
 * Deduplicated via {@see DiscordOutboxGuard} (mirrors
 * {@see AnnounceAndCleanupOnCompleted}) so a re-fired `ServerLinkUpdated`
 * (e.g. a replayed event, or the poll job writing Ready more than once)
 * never posts the embed twice for the same ServerLink.
 *
 * Tournament-level (non-match) ServerLinks have no Discord surface to
 * update and are silently ignored here.
 */
class UpdateMatchSurfacesOnServerReady implements ShouldQueue
{
    public function __construct(
        private readonly DiscordOutboxGuard $guard,
        private readonly DiscordClient $client,
    ) {}

    public function handle(ServerLinkUpdated $event): void
    {
        $link = $event->serverLink;

        if ($link->status !== ServerLinkStatus::Ready || $link->match_id === null) {
            return;
        }

        $match = GameMatch::query()->with(['entry1', 'entry2', 'tournament', 'serverLink'])->find($link->match_id);

        if ($match === null) {
            return;
        }

        $channelId = $match->discord_channels['text_channel_id'] ?? null;

        if ($channelId === null) {
            return;
        }

        $embed = MatchEmbed::welcome($match);

        $this->guard->once(
            "match-{$match->id}-server-{$link->id}-ready",
            'match_server_ready_announced',
            fn () => $this->client->sendMessage($channelId, $embed['title'], [$embed]),
            channelId: $channelId,
            content: $embed['title'],
        );
    }
}
