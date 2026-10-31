<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Testing;

use App\Modules\Provisioning\Contracts\HypervisorClient;
use App\Modules\Provisioning\Contracts\HypervisorClientFactory;
use App\Modules\Provisioning\Models\Hypervisor;

class FakeHypervisorClientFactory implements HypervisorClientFactory
{
    public function __construct(private readonly HypervisorClient $client) {}

    public function for(Hypervisor $hypervisor): HypervisorClient
    {
        return $this->client;
    }
}
