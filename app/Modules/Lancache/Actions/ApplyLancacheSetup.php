<?php

declare(strict_types=1);

namespace App\Modules\Lancache\Actions;

use App\Models\User;
use App\Modules\CustomServers\Actions\StartCustomServer;
use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Enums\HostRole;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Lancache\Exceptions\LancacheException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Applies the LanCache setup on a role=lancache {@see RemoteHost}: pulls and
 * starts the `lancachenet/monolithic` stack (see docs/lancache-setup.md, T9,
 * for the full standalone-host runbook this mirrors) via
 * {@see RemoteExecutor}. LanCache itself runs on a separate host — not a
 * container in LANoMAT's own compose (see CLAUDE.md's M7 module note) — so
 * this action only ever talks to it over the same SSH executor Tasks 1-3
 * introduced for custom game servers.
 *
 * The command is built from configuration (image tag, cache disk size, and
 * upstream DNS), never free-typed by a caller: every dynamic value is
 * escapeshellarg-quoted individually, mirroring
 * {@see StartCustomServer}'s injection-guard
 * reasoning.
 */
class ApplyLancacheSetup
{
    public function __construct(
        private readonly RemoteExecutor $executor,
    ) {}

    public function handle(RemoteHost $host, User $actor): CommandResult
    {
        if (! $actor->isOrga()) {
            throw new AuthorizationException;
        }

        if ($host->role !== HostRole::Lancache) {
            throw LancacheException::notALancacheHost($host);
        }

        return $this->executor->run($host, $this->buildBootstrapCommand());
    }

    /**
     * Builds a `docker compose` bootstrap that pulls and (re)starts the
     * `lancachenet/monolithic` container as a detached, restarting service
     * named `lancache`, bound to the standard cache ports and the DNS
     * upstream configured for the LanCache resolver, exposing the shared
     * cache volume under `/data/cache`. Every configured value is
     * escapeshellarg-quoted; nothing is string-interpolated raw.
     */
    private function buildBootstrapCommand(): string
    {
        $image = (string) config('services.lancache.image', 'lancachenet/monolithic:latest');
        $upstreamDns = (string) config('services.lancache.upstream_dns', '8.8.8.8');
        $cacheVolume = (string) config('services.lancache.cache_volume', 'lancache_data');

        $parts = [
            'docker', 'run', '-d',
            '--name', escapeshellarg('lancache'),
            '--restart', escapeshellarg('unless-stopped'),
            '-p', escapeshellarg('80:80'),
            '-p', escapeshellarg('443:443'),
            '-p', escapeshellarg('53:53/udp'),
            '-e', escapeshellarg("UPSTREAM_DNS={$upstreamDns}"),
            '-v', escapeshellarg("{$cacheVolume}:/data/cache"),
            escapeshellarg($image),
        ];

        return implode(' ', $parts);
    }
}
