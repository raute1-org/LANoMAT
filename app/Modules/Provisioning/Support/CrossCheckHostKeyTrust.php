<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Support;

use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Hosts\SshRemoteExecutor;
use App\Modules\Provisioning\Contracts\HostKeyScanner;
use App\Modules\Provisioning\Contracts\HostKeyTrust;
use App\Modules\Provisioning\Exceptions\ProvisioningException;

/**
 * Mirrors the manual verification in docs/infra/proxmox-test-vm-lancache.md:
 * the host key seen DIRECTLY from the LANoMAT server must equal a key seen
 * THROUGH another host (the hypervisor, `ssh-keyscan` over a RemoteExecutor).
 * Two independent network paths agreeing is what replaces trust on first use;
 * an attacker would have to sit on both. The agreed fingerprint is returned
 * for pinning. Without an independent path there is no trust (error).
 */
class CrossCheckHostKeyTrust implements HostKeyTrust
{
    public function __construct(
        private readonly HostKeyScanner $scanner,
        private readonly RemoteExecutor $executor,
    ) {}

    public function establish(string $address, int $port, ?RemoteHost $independentPath): string
    {
        if ($independentPath === null) {
            throw ProvisioningException::hostKeyUntrusted('No independent path to read the host key: register the hypervisor host as a remote host and attach it to the hypervisor as its jump host.');
        }

        $direct = $this->scanner->scan($address, $port);

        if ($direct === null) {
            throw ProvisioningException::hostKeyUntrusted('The VM does not present an SSH host key yet.');
        }

        $viaJump = $this->viaJumpHost($address, $port, $independentPath);

        if ($viaJump === []) {
            throw ProvisioningException::hostKeyUntrusted('The independent path did not return any host key.');
        }

        if (! in_array($direct, $viaJump, true)) {
            throw ProvisioningException::hostKeyUntrusted('The host key seen directly differs from the key seen through the independent path (possible man-in-the-middle).');
        }

        return $direct;
    }

    /**
     * @return list<string>
     */
    private function viaJumpHost(string $address, int $port, RemoteHost $jump): array
    {
        $result = $this->executor->run($jump, self::keyscanScript($address, $port)->command());
        $fingerprints = [];

        foreach (preg_split('/\R/', $result->stdout) ?: [] as $line) {
            if (preg_match('/^\S+\s+((?:ssh-ed25519|ecdsa-sha2-nistp\d+|ssh-rsa)\s+[A-Za-z0-9+\/=]+)\s*$/', trim($line), $m) === 1) {
                $fingerprints[] = SshRemoteExecutor::deriveFingerprint($m[1]);
            }
        }

        return array_values(array_unique($fingerprints));
    }

    public static function keyscanScript(string $address, int $port): ProvisioningScript
    {
        return ProvisioningScript::make('host-key-scan', 'ssh-keyscan -T 10 -p {{PORT}} -t ed25519,ecdsa,rsa {{ADDRESS}} 2>/dev/null || true', ['PORT' => $port, 'ADDRESS' => $address]);
    }
}
