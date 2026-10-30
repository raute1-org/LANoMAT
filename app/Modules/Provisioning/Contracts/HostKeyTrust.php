<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Contracts;

use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Provisioning\Exceptions\ProvisioningException;

/**
 * Establishes the host key of a freshly created VM WITHOUT trust on first use.
 * The result is what gets pinned on the RemoteHost before any login.
 */
interface HostKeyTrust
{
    /**
     * @param  RemoteHost|null  $independentPath  a host that can reach the VM over a path other than the direct one
     *                                            (the hypervisor host), used to cross-check the key.
     * @return string the trusted `SHA256:...` fingerprint
     *
     * @throws ProvisioningException when the key cannot be confirmed or the two views differ
     */
    public function establish(string $address, int $port, ?RemoteHost $independentPath): string;
}
