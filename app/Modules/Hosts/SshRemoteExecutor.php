<?php

declare(strict_types=1);

namespace App\Modules\Hosts;

use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Domain\HostProbe;
use App\Modules\Hosts\Exceptions\RemoteExecutionException;
use App\Modules\Hosts\Models\RemoteHost;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * Talks SSH to a {@see RemoteHost} using phpseclib3 (verified against the
 * installed 3.0.x source — see task-2-report.md for the exact API facts).
 *
 * Security-critical properties:
 * - The private key is loaded from the decrypted in-memory PEM string
 *   ({@see RemoteHost::$ssh_private_key}, an `encrypted` cast) via
 *   {@see PublicKeyLoader::loadPrivateKey()}. It is NEVER written to a temp
 *   file.
 * - Host-key pinning: on every connect, the server's host key fingerprint is
 *   read and, when `strict_host_key` is enabled and the host already has a
 *   pinned {@see RemoteHost::$host_fingerprint}, the connection is aborted
 *   ({@see RemoteExecutionException::fingerprintMismatch()}) if it doesn't
 *   match. This is what stops a MITM'd host from ever receiving the key or
 *   any command.
 * - Neither the private key nor full command stdout/stderr are logged at
 *   info level (or at all) — a caught {@see Throwable} is translated into a
 *   generic {@see RemoteExecutionException} without embedding secrets.
 */
class SshRemoteExecutor implements RemoteExecutor
{
    public function __construct(
        private readonly int $connectTimeout,
        private readonly bool $strictHostKey,
    ) {}

    public function run(RemoteHost $host, string $command): CommandResult
    {
        $ssh = $this->connect($host);

        try {
            $stdout = $ssh->exec($command);
        } catch (Throwable) {
            throw RemoteExecutionException::commandFailed();
        }

        if (! is_string($stdout)) {
            throw RemoteExecutionException::commandFailed();
        }

        $exitCode = $ssh->getExitStatus();

        return new CommandResult(
            $exitCode === false ? 1 : $exitCode,
            $stdout,
            $ssh->getStdError(),
        );
    }

    public function upload(RemoteHost $host, string $contents, string $remotePath): void
    {
        $sftp = new SFTP($host->hostname, $host->ssh_port, $this->connectTimeout);

        $this->login($sftp, $host);
        $this->assertFingerprintPinned($sftp, $host);

        $ok = $sftp->put($remotePath, $contents, SFTP::SOURCE_STRING);

        if ($ok === false) {
            throw RemoteExecutionException::commandFailed();
        }
    }

    public function probe(RemoteHost $host): HostProbe
    {
        try {
            $ssh = new SSH2($host->hostname, $host->ssh_port, $this->connectTimeout);

            $this->login($ssh, $host);

            $fingerprint = $this->fingerprintOf($ssh);

            if ($this->strictHostKey && $host->host_fingerprint !== null && $fingerprint !== $host->host_fingerprint) {
                return new HostProbe(false, null, 'fingerprint_mismatch');
            }

            return new HostProbe(true, $fingerprint, null);
        } catch (Throwable) {
            return new HostProbe(false, null, 'unreachable');
        }
    }

    private function connect(RemoteHost $host): SSH2
    {
        $ssh = new SSH2($host->hostname, $host->ssh_port, $this->connectTimeout);

        $this->login($ssh, $host);
        $this->assertFingerprintPinned($ssh, $host);

        return $ssh;
    }

    private function login(SSH2 $ssh, RemoteHost $host): void
    {
        try {
            $key = PublicKeyLoader::loadPrivateKey($host->ssh_private_key);
            $ok = $ssh->login($host->ssh_user, $key);
        } catch (Throwable) {
            throw RemoteExecutionException::connectFailed();
        }

        if (! $ok) {
            throw RemoteExecutionException::connectFailed();
        }
    }

    private function assertFingerprintPinned(SSH2 $ssh, RemoteHost $host): void
    {
        if (! $this->strictHostKey || $host->host_fingerprint === null) {
            return;
        }

        $fingerprint = $this->fingerprintOf($ssh);

        if ($fingerprint !== $host->host_fingerprint) {
            throw RemoteExecutionException::fingerprintMismatch();
        }
    }

    /**
     * Derives a SHA256 fingerprint (à la OpenSSH's `ssh-keygen -lf`) from the
     * server's public host key, for pinning against
     * {@see RemoteHost::$host_fingerprint}.
     */
    private function fingerprintOf(SSH2 $ssh): ?string
    {
        $hostKey = $ssh->getServerPublicHostKey();

        if ($hostKey === false) {
            return null;
        }

        // getServerPublicHostKey() returns "<format> <base64-key>"; the
        // fingerprint is computed over the raw key bytes, not the formatted
        // string.
        $parts = explode(' ', $hostKey, 2);
        $rawKey = base64_decode($parts[1] ?? $parts[0]);

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $rawKey, true)), '=');
    }
}
