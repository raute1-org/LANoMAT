<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

final readonly class CreateVmSpec
{
    /**
     * @param  string  $importFromVolume  volume id of the downloaded image (`storage:import/file.qcow2`)
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $node,
        public int $vmid,
        public string $name,
        public int $memoryMb,
        public int $balloonMb,
        public int $cores,
        public string $cpu,
        public string $machine,
        public string $bios,
        public string $bridge,
        public string $scsihw,
        public bool $agent,
        public bool $serial,
        public bool $onboot,
        public string $storage,
        public int $diskSizeGb,
        public string $importFromVolume,
        public CloudInitSpec $cloudInit,
        public array $tags = ['lanomat'],
    ) {}
}
