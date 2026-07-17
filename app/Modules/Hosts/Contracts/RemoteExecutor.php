<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Contracts;

use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Domain\HostProbe;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Hosts\SshRemoteExecutor;
use App\Modules\Hosts\Testing\FakeRemoteExecutor;

/**
 * The only way the app talks SSH to a {@see RemoteHost}. Tasks 3 (custom
 * docker servers) and 4 (LanCache) depend on this contract, never on
 * {@see SshRemoteExecutor} directly, so they can be
 * exercised in tests against {@see FakeRemoteExecutor}
 * with no real SSH connection.
 */
interface RemoteExecutor
{
    public function run(RemoteHost $host, string $command): CommandResult;

    public function upload(RemoteHost $host, string $contents, string $remotePath): void;

    public function probe(RemoteHost $host): HostProbe;
}
