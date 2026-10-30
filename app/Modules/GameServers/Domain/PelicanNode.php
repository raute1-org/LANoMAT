<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Domain;

final readonly class PelicanNode
{
    public function __construct(
        public string $id,
        public string $name,
        public string $fqdn,
        public bool $maintenanceMode = false,
        public int $memoryMb = 0,
        public int $diskMb = 0,
    ) {}
}
