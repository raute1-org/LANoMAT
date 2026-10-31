<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Actions;

use App\Models\User;
use App\Modules\Provisioning\Contracts\HypervisorClientFactory;
use App\Modules\Provisioning\Domain\HypervisorSummary;
use App\Modules\Provisioning\Models\Hypervisor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only probe: version, nodes and the storages of the default node, with
 * a free RAM / disk summary and which storages accept cloud images. Changes
 * nothing on the hypervisor.
 */
class ProbeHypervisor
{
    public function __construct(private readonly HypervisorClientFactory $clients) {}

    public function handle(Hypervisor $hypervisor, User $actor): HypervisorSummary
    {
        Gate::forUser($actor)->authorize('view', $hypervisor);

        $client = $this->clients->for($hypervisor);
        $version = $client->version();

        $summary = new HypervisorSummary(
            version: $version,
            nodes: $client->listNodes(),
            storages: $client->listStorages($hypervisor->node),
            node: $hypervisor->node,
        );

        $hypervisor->last_version = $version;
        $hypervisor->last_probed_at = Carbon::now();
        $hypervisor->save();

        return $summary;
    }
}
