<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Jobs;

use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Listeners\ProvisionMatchServerOnReady;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\EffectiveConfig;
use App\Modules\GameServers\Support\GuardrailPolicy;
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
 *
 * Before that external call, the resolved config is checked against
 * {@see GuardrailPolicy::assertWithinLimits()} (roadmap 6.7: RAM/slot caps
 * plus per-user and node-wide concurrency, enforced here — not only in the
 * UI — so a breach can never reach `createServer()`). This job has no
 * interactive requester (it reacts to `MatchReady`, not a human action), so
 * it passes `null` and the per-user cap is skipped — but the RAM/slot caps
 * and the global `max_running_servers` cap still apply unconditionally, so
 * this automatic path is not unbounded; see `GuardrailPolicy`'s docblock for
 * the full attribution rationale and the exact counting semantics (the
 * just-claimed `ServerLink` is excluded from its own count). A breach
 * re-uses the exact same Failed-and-rethrow handling as a `createServer()`
 * failure below, since the slot (`ServerLink` row + `matches.server_link_id`)
 * was already committed and must not be left dangling in Provisioning.
 */
class ProvisionMatchServerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $matchId,
    ) {}

    public function handle(PelicanClient $client): void
    {
        /** @var array{link: ServerLink, eggId: string, config: array<string, mixed>, game: Game}|null $claim */
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

            // No match/tournament-level preset-or-upload selection mechanism
            // exists yet, so both are passed as null here — EffectiveConfig
            // then falls back to the game's default_server_config, exactly
            // as before this task, but now through the single resolver that
            // also backs the preset/upload modes (see EffectiveConfig's
            // docblock and roadmap 6.6).
            return [
                'link' => $link,
                'eggId' => $game->pelican_egg_id,
                'config' => EffectiveConfig::resolve($game, presetKey: null, uploadedPath: null),
                'game' => $game,
            ];
        });

        if ($claim === null) {
            return;
        }

        $link = $claim['link'];

        try {
            // Enforced here, before the external call: a guardrail breach
            // must mean no server is ever created (see this job's docblock
            // and GuardrailPolicy's). No interactive requester exists for
            // this automatic path, so the per-user cap is skipped (null) —
            // but the RAM/slot caps AND the global running-server cap still
            // apply unconditionally, which is what gives this path teeth.
            // The just-claimed $link (already saved as Provisioning above)
            // is excluded from the global count — see GuardrailPolicy's
            // docblock for the exact "at most N besides this one" semantics.
            GuardrailPolicy::assertWithinLimits($claim['game'], $claim['config'], requester: null, excludingLinkId: $link->id);

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
