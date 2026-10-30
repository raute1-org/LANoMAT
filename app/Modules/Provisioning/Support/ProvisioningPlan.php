<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Support;

use App\Modules\Provisioning\Contracts\ProvisioningStep;

/**
 * An ordered list of steps. Plans are built by a registered factory (see
 * {@see ProvisioningPlans}); the order is the contract (isolation last).
 */
final readonly class ProvisioningPlan
{
    /**
     * @param  list<ProvisioningStep>  $steps
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $steps,
    ) {}
}
