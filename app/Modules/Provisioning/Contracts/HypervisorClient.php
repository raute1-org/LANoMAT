<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Contracts;

use App\Modules\Provisioning\Domain\CreateVmSpec;
use App\Modules\Provisioning\Domain\HypervisorNode;
use App\Modules\Provisioning\Domain\HypervisorStorage;
use App\Modules\Provisioning\Domain\ImageSource;
use App\Modules\Provisioning\Domain\VmSummary;
use App\Modules\Provisioning\Enums\VmPowerState;
use App\Modules\Provisioning\Exceptions\HypervisorException;

/**
 * The only way the app talks to a hypervisor (Proxmox VE). One instance is
 * bound to one hypervisor (credentials); {@see HypervisorClientFactory}
 * builds it. Every mutating call returns only after the hypervisor task
 * finished successfully (implementations wait for the task), so a step can
 * rely on "returned" meaning "done".
 *
 * @throws HypervisorException on any failure, with a German message key.
 */
interface HypervisorClient
{
    public function version(): string;

    /** @return list<HypervisorNode> */
    public function listNodes(): array;

    /** @return list<HypervisorStorage> */
    public function listStorages(string $node): array;

    /** @return list<VmSummary> */
    public function listVms(string $node): array;

    /** The VM, or null when no VM with that id exists on the node. */
    public function getVm(string $node, int $vmid): ?VmSummary;

    public function nextFreeVmid(): int;

    /**
     * Creates the VM (disk imported from {@see CreateVmSpec::$importFromVolume},
     * resized to the spec size, cloud-init drive attached). It does not start it.
     */
    public function createVm(CreateVmSpec $spec): void;

    /** Imports a downloaded image volume as a new disk of an existing VM (`import-from`). */
    public function importDisk(string $node, int $vmid, string $disk, string $storage, string $volume): void;

    /** Sets a disk key (for example `scsi1`) of an existing VM to a raw Proxmox disk value. */
    public function attachDisk(string $node, int $vmid, string $disk, string $value): void;

    /** Grows a disk to an absolute size in GiB (never shrinks). */
    public function resizeDisk(string $node, int $vmid, string $disk, int $sizeGb): void;

    public function startVm(string $node, int $vmid): void;

    /** Hard stop (the qemu process exits). */
    public function stopVm(string $node, int $vmid): void;

    /** The VM must be stopped. `$purge` also removes it from backup/HA/replication jobs. */
    public function deleteVm(string $node, int $vmid, bool $purge = true): void;

    public function vmStatus(string $node, int $vmid): VmPowerState;

    /** True when the QEMU guest agent answers; false when it is not running (never throws for that). */
    public function agentPing(string $node, int $vmid): bool;

    /** True when the volume (`storage:import/file.qcow2`) exists on the storage. */
    public function hasVolume(string $node, string $storage, string $volume): bool;

    /**
     * Downloads a cloud image onto `$storage` (content type `import`),
     * verifying the mandatory checksum on the hypervisor. Returns the volume id.
     */
    public function downloadImage(string $node, string $storage, ImageSource $image): string;
}
