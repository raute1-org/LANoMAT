<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Contracts;

use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Domain\HostProbe;
use App\Modules\Hosts\Exceptions\RemoteExecutionException;
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

    /**
     * Streams a local file to the host over SFTP without reading it into
     * memory (multi-GB safe). The remote parent directory is created when
     * missing; the bytes land in a `.part` file that is renamed into place only
     * after the transfer completed, and the partial file is removed on failure,
     * so `$remotePath` either holds the complete file or does not exist.
     *
     * SFTP-only on purpose: a chrooted `internal-sftp` account cannot run shell
     * commands, so this and its siblings never go through {@see run()}.
     *
     * @throws RemoteExecutionException
     */
    public function uploadFile(RemoteHost $host, string $localPath, string $remotePath): void;

    /**
     * Removes a remote file over SFTP. A file that is already gone is not an
     * error (idempotent), an unreachable host is.
     *
     * @throws RemoteExecutionException
     */
    public function deleteFile(RemoteHost $host, string $remotePath): void;

    /**
     * @throws RemoteExecutionException
     */
    public function fileExists(RemoteHost $host, string $remotePath): bool;
}
