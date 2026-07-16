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
use Illuminate\Support\Facades\DB;
use Throwable;

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
 *
 * The guard-then-write is done as an atomic slot claim to close a
 * double-provision race: two concurrent dispatches for the same match (a
 * duplicate `MatchReady` from a retry, or a manual re-dispatch) could both
 * pass the null-check before either persisted `server_link_id`, creating two
 * `ServerLink` rows and two real Pelican servers. Instead, the match row is
 * locked (`lockForUpdate`), the null-check is re-done under the lock, and the
 * `ServerLink` row plus `matches.server_link_id` are both written *before*
 * the transaction commits — so a second concurrent run blocks on the lock
 * and then sees the slot already claimed and no-ops. The external
 * `PelicanClient::createServer()` call deliberately happens *after* the
 * transaction commits (lock released), so the DB lock is never held across
 * network IO.
 */
class ProvisionMatchServerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $matchId,
    ) {}

    public function handle(PelicanClient $client): void
    {
        /** @var array{link: ServerLink, eggId: string, config: array<string, mixed>}|null $claim */
        $claim = DB::transaction(function (): ?array {
            $match = GameMatch::query()->with('tournament.game')->whereKey($this->matchId)->lockForUpdate()->first();

            if ($match === null) {
                return null;
            }

            if ($match->server_link_id !== null) {
                return null;
            }

            $game = $match->tournament?->game;

            if ($game === null || $game->pelican_egg_id === null) {
                throw GameServerException::noPelicanEgg();
            }

            $link = new ServerLink(['match_id' => $match->id]);
            $link->status = ServerLinkStatus::Provisioning;
            $link->save();

            // server_link_id is deliberately not fillable (see GameMatch's
            // docblock) — set via forceFill, mirroring MatchProgression's
            // pattern for the match's other provisioning-only fields. This
            // claims the slot: it is written in the same transaction as the
            // ServerLink row, before the external call, so the row lock
            // fully serializes concurrent dispatches against this race.
            $match->forceFill(['server_link_id' => $link->id])->save();

            return [
                'link' => $link,
                'eggId' => $game->pelican_egg_id,
                'config' => $game->default_server_config->toArray(),
            ];
        });

        if ($claim === null) {
            return;
        }

        $link = $claim['link'];

        try {
            $server = $client->createServer($claim['eggId'], $claim['config']);
        } catch (Throwable $e) {
            $link->status = ServerLinkStatus::Failed;
            $link->save();

            throw $e;
        }

        $link->pelican_server_id = $server->id;
        $link->save();

        PollServerStatusJob::dispatch($link->id)->delay(now()->addSeconds(10));
    }
}
