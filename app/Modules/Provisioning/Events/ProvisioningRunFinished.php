<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class ProvisioningRunFinished
{
    use Dispatchable;

    public function __construct(
        public int $runId,
        public string $status,
    ) {}
}
