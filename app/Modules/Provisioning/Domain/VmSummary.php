<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

use App\Modules\Provisioning\Enums\VmPowerState;

final readonly class VmSummary
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public int $vmid,
        public string $name,
        public VmPowerState $status,
        public int $cpus,
        public int $maxMem,
        public int $maxDisk,
        public array $tags = [],
    ) {}
}
