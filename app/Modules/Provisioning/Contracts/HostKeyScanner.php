<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Contracts;

/**
 * Reads the SSH host key a machine presents (transport handshake only, no
 * login) as seen from the LANoMAT server.
 */
interface HostKeyScanner
{
    /** The SHA256 fingerprint (`SHA256:...`) of the presented host key, or null when nothing answers. */
    public function scan(string $address, int $port): ?string;
}
