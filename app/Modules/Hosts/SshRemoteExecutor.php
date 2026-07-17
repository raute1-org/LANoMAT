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
 * - Host-key pinning happens BEFORE authentication. `SSH2::getServerPublicHostKey()`
 *   forces only the transport connect + key exchange (verified against the
 *   installed phpseclib3 source: it does not call `login()`), so every entry
 *   point ({@see connect()}/`run`, {@see upload()}, {@see probe()}) reads the
 *   server host key and calls {@see assertFingerprintPinned()} first. When
 *   `strict_host_key` is enabled and the host already has a pinned
 *   {@see RemoteHost::$host_fingerprint}, a mismatch aborts
 *   ({@see RemoteExecutionException::fingerprintMismatch()}) before `login()`
 *   is ever called — mirroring OpenSSH's `known_hosts` check, which happens
 *   before any auth. This is what stops a MITM'd host from completing
 *   authentication (a signature proof-of-possession) at all.
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

        // Pre-login ordering: read + pin-check the host key BEFORE login().
        $this->assertFingerprintPinned($host, $this->fingerprintOf($sftp));
        $this->login($sftp, $host);

        $ok = $sftp->put($remotePath, $contents, SFTP::SOURCE_STRING);

        if ($ok === false) {
            throw RemoteExecutionException::commandFailed();
        }
    }

    public function probe(RemoteHost $host): HostProbe
    {
        try {
            $ssh = new SSH2($host->hostname, $host->ssh_port, $this->connectTimeout);

            // Pre-login ordering: read the host key and abort on a pinned
            // mismatch BEFORE ever calling login() — probe must not
            // authenticate to a host presenting an unrecognized key either.
            $fingerprint = $this->fingerprintOf($ssh);

            try {
                $this->assertFingerprintPinned($host, $fingerprint);
            } catch (RemoteExecutionException) {
                return new HostProbe(false, null, 'fingerprint_mismatch');
            }

            $this->login($ssh, $host);

            return new HostProbe(true, $fingerprint, null);
        } catch (Throwable) {
            return new HostProbe(false, null, 'unreachable');
        }
    }

    private function connect(RemoteHost $host): SSH2
    {
        $ssh = new SSH2($host->hostname, $host->ssh_port, $this->connectTimeout);

        // Pre-login ordering: read + pin-check the host key BEFORE login().
        // getServerPublicHostKey() forces the transport connect + KEX only
        // (verified in vendor source — see class docblock) and does not
        // authenticate, so a fingerprint mismatch aborts before any
        // proof-of-possession signature is sent to the remote host.
        $this->assertFingerprintPinned($host, $this->fingerprintOf($ssh));
        $this->login($ssh, $host);

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

    /**
     * The single place the pinning rule lives. Called by every entry point
     * (`connect()`/`run`, `upload()`, `probe()`) with the fingerprint just
     * read from the transport, BEFORE `login()`.
     */
    private function assertFingerprintPinned(RemoteHost $host, ?string $actualFingerprint): void
    {
        if (! $this->strictHostKey || $host->host_fingerprint === null) {
            return;
        }

        if (! self::fingerprintMatches($host->host_fingerprint, $actualFingerprint)) {
            throw RemoteExecutionException::fingerprintMismatch();
        }
    }

    /**
     * Pure comparison, extracted so the pinning decision is unit-testable
     * without a real SSH connection: given a stored pin and an actual
     * fingerprint, does it match? `null` (host key unreadable) never
     * matches a stored pin.
     */
    public static function fingerprintMatches(string $pinned, ?string $actualFingerprint): bool
    {
        return $actualFingerprint !== null && $actualFingerprint === $pinned;
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

        return self::deriveFingerprint($hostKey);
    }

    /**
     * Pure SHA256 fingerprint derivation, extracted from {@see fingerprintOf()}
     * so it is unit-testable against known `ssh-keygen -lf` output without a
     * real SSH connection. Input is the `"<format> <base64-key>"` string as
     * returned by `SSH2::getServerPublicHostKey()`; the fingerprint is
     * computed over the raw key bytes, not the formatted string.
     */
    public static function deriveFingerprint(string $formattedHostKey): string
    {
        $parts = explode(' ', $formattedHostKey, 2);
        $rawKey = base64_decode($parts[1] ?? $parts[0]);

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $rawKey, true)), '=');
    }
}
