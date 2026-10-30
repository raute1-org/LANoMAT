<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Jobs;

use App\Modules\GameServers\Actions\SetManualJoinInfo;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Domain\PelicanServer;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\InstallVerifier;
use App\Modules\GameServers\Support\ReadinessPlan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Polls a provisioned server's install and runtime state
 * ({@see PelicanClient::getServer()}, which merges the Application API's
 * install status with the Client API's real running/offline state) after
 * {@see ProvisionMatchServerJob} kicked off creation:
 *
 * - {@see ServerState::Running} -> writes the join details as a
 *   {@see JoinInfo}, flips the {@see ServerLink} to
 *   {@see ServerLinkStatus::Ready}, and dispatches {@see ServerLinkUpdated}
 *   (Task 5 pushes this onto the match page/Discord embed).
 * - {@see ServerState::Installing}/{@see ServerState::Provisioning} -> not
 *   ready yet; re-dispatches itself (a fresh job instance with `$attempt`
 *   incremented) with a delay, bounded by {@see MAX_ATTEMPTS} so a panel that
 *   never finishes installing does not poll forever.
 * - {@see ServerState::Failed}, or the retry budget being exhausted -> flips
 *   the link to {@see ServerLinkStatus::Failed}, surfacing the manual
 *   fallback ({@see SetManualJoinInfo}) in
 *   the UI.
 *
 * The retry count is tracked via the explicit `$attempt` constructor
 * argument rather than the queue's own `$job->attempts()`/`$tries`: each
 * "still installing" outcome dispatches a brand-new job instance (see
 * bottom of {@see handle()}) rather than releasing this one back onto the
 * queue, so the framework's own attempt counter would never advance past 1.
 */
class PollServerStatusJob implements ShouldQueue
{
    use Queueable;

    /**
     * Bounds the polling window: with a 10s delay between attempts, 30
     * attempts is a 5-minute ceiling before giving up and surfacing manual
     * fallback.
     */
    private const int MAX_ATTEMPTS = 30;

    private const int DELAY_SECONDS = 10;

    /** Reinstalls LANoMAT triggers after a failed install verification. */
    private const int MAX_REINSTALLS = 1;

    public function __construct(
        public readonly int $serverLinkId,
        public readonly int $attempt = 1,
    ) {}

    public function handle(PelicanClient $client): void
    {
        $link = ServerLink::query()->find($this->serverLinkId);

        if ($link === null || $link->status !== ServerLinkStatus::Provisioning) {
            return;
        }

        if ($link->pelican_server_id === null) {
            return;
        }

        $server = $client->getServer($link->pelican_server_id);

        if ($link->pelican_server_identifier === null && $server->identifier !== null) {
            $link->pelican_server_identifier = $server->identifier;
        }

        $plan = $link->readiness ?? [];

        // The install is still running: wait.
        if ($server->state === ServerState::Installing || $server->state === ServerState::Provisioning) {
            $this->pollAgain($link, $plan);

            return;
        }

        // Install verification (presets only): the panel's `status` can say installed after a
        // failed SteamCMD run, so look for the marker path; retry the install once.
        $marker = is_string($plan['install_marker'] ?? null) ? $plan['install_marker'] : '';

        if ($marker !== '' && ! ($plan['install_verified'] ?? false)) {
            $present = $server->state === ServerState::Failed
                ? false
                : InstallVerifier::markerPresent($client, (string) $link->pelican_server_identifier, $marker);

            if ($present === null) {
                $this->pollAgain($link, $plan);

                return;
            }

            if ($present === false) {
                $this->handleIncompleteInstall($client, $link, $plan);

                return;
            }

            $plan['install_verified'] = true;
            $link->readiness = $plan;
        }

        if ($server->state === ServerState::Running) {
            $this->publishJoinInfo($link, $server, $plan);

            return;
        }

        if ($server->state === ServerState::Failed) {
            $link->status = ServerLinkStatus::Failed;
            $link->save();

            return;
        }

        $this->pollAgain($link, $plan);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function publishJoinInfo(ServerLink $link, PelicanServer $server, array $plan): void
    {
        $password = $link->join_info->password;
        $link->join_info = new JoinInfo(
            address: $server->address,
            port: $server->port,
            password: $password,
            connectString: ReadinessPlan::renderConnect(
                is_string($plan['connect_template'] ?? null) ? $plan['connect_template'] : null,
                $server->address,
                $server->port,
                $password,
            ),
        );

        if (($plan['type'] ?? null) === 'a2s' && $server->address !== null && $server->port !== null) {
            // Running on the panel is not "ready": wait for the server to answer its query.
            $link->save();

            CheckServerReadinessJob::dispatch($link->id)->delay(now()->addSeconds(CheckServerReadinessJob::INTERVAL_SECONDS));

            return;
        }

        $link->status = ServerLinkStatus::Ready;
        $link->save();

        ServerLinkUpdated::dispatch($link);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function handleIncompleteInstall(PelicanClient $client, ServerLink $link, array $plan): void
    {
        if ($link->install_attempts < self::MAX_REINSTALLS) {
            $client->reinstallServer((string) $link->pelican_server_id);
            $link->install_attempts++;
            $link->save();

            $this->pollAgain($link, $plan);

            return;
        }

        $link->status = ServerLinkStatus::InstallFailed;
        $link->save();

        ServerLinkUpdated::dispatch($link);
    }

    /**
     * Re-dispatches itself (a fresh instance with `$attempt` incremented) until the
     * polling window is used up, then gives up with Failed. Presets get a longer
     * window (`services.pelican.install_timeout`): a Source game is several GB.
     *
     * @param  array<string, mixed>  $plan
     */
    private function pollAgain(ServerLink $link, array $plan): void
    {
        $max = $plan === [] ? self::MAX_ATTEMPTS : max(self::MAX_ATTEMPTS, intdiv((int) config('services.pelican.install_timeout', 900), self::DELAY_SECONDS));

        if ($this->attempt >= $max) {
            $link->status = ServerLinkStatus::Failed;
            $link->save();

            return;
        }

        $link->save();

        self::dispatch($this->serverLinkId, $this->attempt + 1)->delay(now()->addSeconds(self::DELAY_SECONDS));
    }
}
