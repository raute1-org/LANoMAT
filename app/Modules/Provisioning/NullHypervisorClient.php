<?php

declare(strict_types=1);

namespace App\Modules\Provisioning;

use App\Modules\Provisioning\Contracts\HypervisorClient;
use App\Modules\Provisioning\Domain\CreateVmSpec;
use App\Modules\Provisioning\Domain\ImageSource;
use App\Modules\Provisioning\Domain\VmSummary;
use App\Modules\Provisioning\Enums\VmPowerState;
use App\Modules\Provisioning\Exceptions\HypervisorException;

/**
 * Bound when no hypervisor is configured: every call fails with a clear
 * German "not configured" error instead of a half-working client.
 */
class NullHypervisorClient implements HypervisorClient
{
    public function version(): string
    {
        throw HypervisorException::notConfigured();
    }

    public function listNodes(): array
    {
        throw HypervisorException::notConfigured();
    }

    public function listStorages(string $node): array
    {
        throw HypervisorException::notConfigured();
    }

    public function listVms(string $node): array
    {
        throw HypervisorException::notConfigured();
    }

    public function getVm(string $node, int $vmid): ?VmSummary
    {
        throw HypervisorException::notConfigured();
    }

    public function nextFreeVmid(): int
    {
        throw HypervisorException::notConfigured();
    }

    public function createVm(CreateVmSpec $spec): void
    {
        throw HypervisorException::notConfigured();
    }

    public function importDisk(string $node, int $vmid, string $disk, string $storage, string $volume): void
    {
        throw HypervisorException::notConfigured();
    }

    public function attachDisk(string $node, int $vmid, string $disk, string $value): void
    {
        throw HypervisorException::notConfigured();
    }

    public function resizeDisk(string $node, int $vmid, string $disk, int $sizeGb): void
    {
        throw HypervisorException::notConfigured();
    }

    public function startVm(string $node, int $vmid): void
    {
        throw HypervisorException::notConfigured();
    }

    public function stopVm(string $node, int $vmid): void
    {
        throw HypervisorException::notConfigured();
    }

    public function deleteVm(string $node, int $vmid, bool $purge = true): void
    {
        throw HypervisorException::notConfigured();
    }

    public function vmStatus(string $node, int $vmid): VmPowerState
    {
        throw HypervisorException::notConfigured();
    }

    public function agentPing(string $node, int $vmid): bool
    {
        throw HypervisorException::notConfigured();
    }

    public function hasVolume(string $node, string $storage, string $volume): bool
    {
        throw HypervisorException::notConfigured();
    }

    public function downloadImage(string $node, string $storage, ImageSource $image): string
    {
        throw HypervisorException::notConfigured();
    }
}
