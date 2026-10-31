<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Contracts;

use App\Modules\Provisioning\Models\Hypervisor;

/**
 * Credentials live in database rows, so the container cannot bind a single
 * client: it binds this factory, which builds the client for one
 * {@see Hypervisor}. Tests bind a factory that always returns the fake.
 */
interface HypervisorClientFactory
{
    public function for(Hypervisor $hypervisor): HypervisorClient;
}
