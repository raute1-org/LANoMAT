<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Support;

use App\Modules\Hosts\SshRemoteExecutor;
use App\Modules\Provisioning\Contracts\HostKeyScanner;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * Reads the presented host key with phpseclib: transport handshake only,
 * `getServerPublicHostKey()` never authenticates. ed25519 is preferred so the
 * result can be compared with `ssh-keyscan -t ed25519` on the other path.
 */
class PhpseclibHostKeyScanner implements HostKeyScanner
{
    public function __construct(private readonly int $timeoutSeconds = 5) {}

    public function scan(string $address, int $port): ?string
    {
        try {
            $ssh = new SSH2($address, $port, $this->timeoutSeconds);
            $ssh->setPreferredAlgorithms(['hostkey' => ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'rsa-sha2-512', 'rsa-sha2-256']]);
            $key = $ssh->getServerPublicHostKey();

            return $key === false ? null : SshRemoteExecutor::deriveFingerprint($key);
        } catch (Throwable) {
            return null;
        }
    }
}
