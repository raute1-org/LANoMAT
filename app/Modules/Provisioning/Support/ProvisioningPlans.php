<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Support;

use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Plans\TestVmWithLancache;

/**
 * Registry of the known plans (resolved from the container so steps get their
 * contracts, which tests replace with fakes).
 */
class ProvisioningPlans
{
    public function get(string $key): ProvisioningPlan
    {
        return match ($key) {
            TestVmWithLancache::KEY => app(TestVmWithLancache::class)->plan(),
            default => throw ProvisioningException::unknownPlan($key),
        };
    }

    /** @return list<string> */
    public function keys(): array
    {
        return [TestVmWithLancache::KEY];
    }
}
