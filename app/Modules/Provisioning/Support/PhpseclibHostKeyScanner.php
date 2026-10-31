<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Support;

use App\Modules\Hosts\SshRemoteExecutor;
use App\Modules\Provisioning\Contracts\HostKeyScanner;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * Reads the presented host key with phpseclib: transport handshake only,
 * `getServerPublicHostKey()` never authenticates. It deliberately uses the same
 * default negotiation as SshRemoteExecutor, so the key type that gets pinned is
 * the one every later login will see; the cross-check accepts any key type that
 * `ssh-keyscan` returns on the independent path.
 */
class PhpseclibHostKeyScanner implements HostKeyScanner
{
    public function __construct(private readonly int $timeoutSeconds = 5) {}

    public function scan(string $address, int $port): ?string
    {
        try {
            $ssh = new SSH2($address, $port, $this->timeoutSeconds);
            $key = $ssh->getServerPublicHostKey();

            return $key === false ? null : SshRemoteExecutor::deriveFingerprint($key);
        } catch (Throwable) {
            return null;
        }
    }
}
