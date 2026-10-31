<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Progress signal: ids and status only (no PII, no log text). */
final readonly class ProvisioningStepChanged
{
    use Dispatchable;

    public function __construct(
        public int $runId,
        public string $step,
        public string $status,
    ) {}
}
