<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Domain;

/**
 * Resource and feature limits of a Pelican server, in the units the
 * Application API uses: memory/disk in MB (0 = unlimited), swap in MB
 * (-1 = unlimited, 0 = disabled), io 10..1000 (block-IO weight), cpu in
 * percent of one thread (0 = unlimited). `databases`/`backups` are the
 * feature limits (`null` = unlimited).
 */
final readonly class ServerLimits
{
    public function __construct(
        public int $memory,
        public int $disk,
        public int $cpu = 0,
        public int $swap = 0,
        public int $io = 500,
        public ?int $databases = 0,
        public ?int $backups = 0,
        public ?int $allocations = null,
    ) {}

    /**
     * @return array{memory: int, swap: int, disk: int, io: int, cpu: int}
     */
    public function toLimitsPayload(): array
    {
        return [
            'memory' => $this->memory,
            'swap' => $this->swap,
            'disk' => $this->disk,
            'io' => $this->io,
            'cpu' => $this->cpu,
        ];
    }

    /**
     * @return array{databases: int|null, allocations?: int|null, backups: int|null}
     */
    public function toFeatureLimitsPayload(): array
    {
        $payload = ['databases' => $this->databases, 'backups' => $this->backups];

        if ($this->allocations !== null) {
            $payload['allocations'] = $this->allocations;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $limits
     * @param  array<string, mixed>  $featureLimits
     */
    public static function fromPayload(array $limits, array $featureLimits = []): self
    {
        return new self(
            memory: (int) ($limits['memory'] ?? 0),
            disk: (int) ($limits['disk'] ?? 0),
            cpu: (int) ($limits['cpu'] ?? 0),
            swap: (int) ($limits['swap'] ?? 0),
            io: (int) ($limits['io'] ?? 500),
            databases: isset($featureLimits['databases']) ? (int) $featureLimits['databases'] : null,
            backups: isset($featureLimits['backups']) ? (int) $featureLimits['backups'] : null,
            allocations: isset($featureLimits['allocations']) ? (int) $featureLimits['allocations'] : null,
        );
    }
}
