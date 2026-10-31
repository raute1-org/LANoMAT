<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

final readonly class HypervisorNode
{
    public function __construct(
        public string $name,
        public bool $online,
        public int $maxCpu,
        public int $memTotal,
        public int $memUsed,
        public ?string $sslFingerprint = null,
        public int $uptime = 0,
    ) {}

    public function memFree(): int
    {
        return max(0, $this->memTotal - $this->memUsed);
    }
}
