<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

/**
 * Read-only probe result of a hypervisor (version, nodes, storages).
 */
final readonly class HypervisorSummary
{
    /**
     * @param  list<HypervisorNode>  $nodes
     * @param  list<HypervisorStorage>  $storages  storages of the probed node
     */
    public function __construct(
        public string $version,
        public array $nodes,
        public array $storages,
        public string $node,
    ) {}

    public function memFreeBytes(): int
    {
        foreach ($this->nodes as $node) {
            if ($node->name === $this->node) {
                return $node->memFree();
            }
        }

        return 0;
    }

    /** @return list<HypervisorStorage> storages that can hold VM disks. */
    public function diskStorages(): array
    {
        return array_values(array_filter($this->storages, fn (HypervisorStorage $s): bool => $s->usable() && $s->acceptsContent('images')));
    }

    /** @return list<HypervisorStorage> storages that accept cloud images (content type `import`). */
    public function importStorages(): array
    {
        return array_values(array_filter($this->storages, fn (HypervisorStorage $s): bool => $s->usable() && $s->acceptsContent('import')));
    }

    public function freeDiskBytes(): int
    {
        return array_sum(array_map(fn (HypervisorStorage $s): int => $s->avail, $this->diskStorages()));
    }
}
