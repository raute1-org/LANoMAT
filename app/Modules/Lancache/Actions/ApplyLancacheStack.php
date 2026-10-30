<?php

declare(strict_types=1);

namespace App\Modules\Lancache\Actions;

use App\Models\User;
use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Enums\HostRole;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Lancache\Domain\LancacheConfig;
use App\Modules\Lancache\Exceptions\LancacheException;
use App\Modules\Lancache\Support\LancacheStack;
use Illuminate\Support\Facades\Gate;

/**
 * Applies the typed compose-based LanCache stack on a role=lancache host:
 * renders `.env` and `docker-compose.yml` from a {@see LancacheConfig},
 * uploads them, then `compose config -q`, `pull` and `up -d`. Idempotent:
 * returns false without touching anything when files and containers already
 * match. (`ApplyLancacheSetup` keeps the legacy single `docker run` bootstrap.)
 */
class ApplyLancacheStack
{
    public function __construct(private readonly RemoteExecutor $executor) {}

    /** @return bool true when the stack was (re)applied, false when it already matched */
    public function handle(RemoteHost $host, LancacheConfig $config, User $actor): bool
    {
        Gate::forUser($actor)->authorize('update', $host);

        if ($host->role !== HostRole::Lancache) {
            throw LancacheException::notALancacheHost($host);
        }

        if ($this->run($host, LancacheStack::satisfied($config), false)->ok()) {
            return false;
        }

        $this->run($host, LancacheStack::resolverCheck($config));
        $this->run($host, LancacheStack::prepare($config));

        $this->executor->upload($host, $config->envFile(), LancacheStack::tmpPath(LancacheStack::ENV_FILE));
        $this->run($host, LancacheStack::install(LancacheStack::ENV_FILE, '0640'));
        $this->executor->upload($host, $config->composeFile(), LancacheStack::tmpPath(LancacheStack::COMPOSE_FILE));
        $this->run($host, LancacheStack::install(LancacheStack::COMPOSE_FILE, '0644'));

        $this->run($host, LancacheStack::up());

        return true;
    }

    private function run(RemoteHost $host, string $script, bool $mustSucceed = true): CommandResult
    {
        $result = $this->executor->run($host, 'bash -c '.escapeshellarg("set -euo pipefail\n".$script));

        if ($mustSucceed && ! $result->ok()) {
            throw LancacheException::commandFailed();
        }

        return $result;
    }
}
