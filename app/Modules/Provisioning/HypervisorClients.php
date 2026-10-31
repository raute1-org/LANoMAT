<?php

declare(strict_types=1);

namespace App\Modules\Provisioning;

use App\Modules\Provisioning\Contracts\HypervisorClient;
use App\Modules\Provisioning\Contracts\HypervisorClientFactory;
use App\Modules\Provisioning\Models\Hypervisor;
use App\Modules\Provisioning\Testing\FakeHypervisorClient;

/**
 * Production factory: builds a {@see ProxmoxApiClient} from a credential row.
 * `provisioning.driver=fake` hands out one shared in-memory fake (local
 * development without a hypervisor).
 */
class HypervisorClients implements HypervisorClientFactory
{
    private ?FakeHypervisorClient $fake = null;

    public function for(Hypervisor $hypervisor): HypervisorClient
    {
        if (config('provisioning.driver') === 'fake') {
            return $this->fake ??= new FakeHypervisorClient;
        }

        if ($hypervisor->base_url === '' || $hypervisor->token_id === '' || $hypervisor->token_secret === '') {
            return new NullHypervisorClient;
        }

        return new ProxmoxApiClient(
            baseUrl: $hypervisor->base_url,
            tokenId: $hypervisor->token_id,
            tokenSecret: $hypervisor->token_secret,
            tlsPin: $hypervisor->tls_pin,
            timeoutSeconds: (int) config('provisioning.proxmox.timeout'),
            connectTimeoutSeconds: (int) config('provisioning.proxmox.connect_timeout'),
            taskTimeoutSeconds: (int) config('provisioning.proxmox.task_timeout'),
            taskPollSeconds: (int) config('provisioning.proxmox.task_poll_seconds'),
        );
    }
}
