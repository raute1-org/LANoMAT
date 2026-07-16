<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Jobs;

use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Listeners\ProvisionMatchServerOnReady;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Voice\Jobs\ProvisionMatchVoiceJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Provisions a Pelican game server for a match once it becomes playable
 * ({@see MatchReady}, via
 * {@see ProvisionMatchServerOnReady}):
 * creates a {@see ServerLink} row (status Provisioning), asks
 * {@see PelicanClient::createServer()} for a server using the tournament's
 * game `default_server_config`, stores the returned server id, and schedules
 * {@see PollServerStatusJob} to pick up the result once the panel finishes
 * installing it.
 *
 * Idempotent: skips entirely once `matches.server_link_id` is already set,
 * so a re-fired `MatchReady` never provisions a second server — mirrors
 * {@see ProvisionMatchVoiceJob}'s
 * `voice_channels !== null` guard.
 */
class ProvisionMatchServerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $matchId,
    ) {}

    public function handle(PelicanClient $client): void
    {
        $match = GameMatch::query()->with('tournament.game')->find($this->matchId);

        if ($match === null) {
            return;
        }

        if ($match->server_link_id !== null) {
            return;
        }

        $game = $match->tournament?->game;

        if ($game === null || $game->pelican_egg_id === null) {
            throw GameServerException::noPelicanEgg();
        }

        $link = new ServerLink(['match_id' => $match->id]);
        $link->status = ServerLinkStatus::Provisioning;
        $link->save();

        $server = $client->createServer(
            $game->pelican_egg_id,
            $game->default_server_config->toArray(),
        );

        $link->pelican_server_id = $server->id;
        $link->save();

        // server_link_id is deliberately not fillable (see GameMatch's
        // docblock) — set via forceFill, mirroring MatchProgression's
        // pattern for the match's other provisioning-only fields.
        $match->forceFill(['server_link_id' => $link->id])->save();

        PollServerStatusJob::dispatch($link->id)->delay(now()->addSeconds(10));
    }
}
