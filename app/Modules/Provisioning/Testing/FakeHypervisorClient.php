<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Testing;

use App\Modules\Provisioning\Contracts\HypervisorClient;
use App\Modules\Provisioning\Domain\CreateVmSpec;
use App\Modules\Provisioning\Domain\HypervisorNode;
use App\Modules\Provisioning\Domain\HypervisorStorage;
use App\Modules\Provisioning\Domain\ImageSource;
use App\Modules\Provisioning\Domain\VmSummary;
use App\Modules\Provisioning\Enums\VmPowerState;
use App\Modules\Provisioning\Exceptions\HypervisorException;
use PHPUnit\Framework\Assert;

/**
 * Deterministic in-memory hypervisor (tests and local development). One node
 * `node1`, storages `local` (import, iso) and `local-lvm` (images). VM ids
 * count up from 100. Every call is recorded in {@see $calls}.
 */
class FakeHypervisorClient implements HypervisorClient
{
    /** @var list<array{method: string, args: array<int, mixed>}> */
    public array $calls = [];

    /** @var array<int, VmSummary> */
    public array $vms = [];

    /** @var array<int, CreateVmSpec> */
    public array $specs = [];

    /** @var list<string> */
    public array $downloads = [];

    /** @var list<string> */
    public array $volumes = [];

    /** @var array<string, true> methods that should throw a task failure */
    public array $failing = [];

    public bool $agentAnswers = true;

    /** Simulates a create task that allocated the VM but a later task (resize) failed. */
    public bool $failAfterCreate = false;

    public int $nextVmid = 100;

    /** @var list<HypervisorStorage> */
    public array $storages;

    public function __construct()
    {
        $this->storages = [
            new HypervisorStorage('local', 'dir', ['iso', 'vztmpl', 'import'], true, true, false, 100_000_000_000, 50_000_000_000, 50_000_000_000),
            new HypervisorStorage('local-lvm', 'lvmthin', ['rootdir', 'images'], true, true, false, 800_000_000_000, 700_000_000_000, 100_000_000_000),
        ];
    }

    public function failOn(string $method): self
    {
        $this->failing[$method] = true;

        return $this;
    }

    public function version(): string
    {
        $this->record(__FUNCTION__, []);

        return '9.0.0-fake';
    }

    public function listNodes(): array
    {
        $this->record(__FUNCTION__, []);

        return [new HypervisorNode('node1', true, 16, 64_000_000_000, 16_000_000_000, 'AA:BB:CC:DD', 3600)];
    }

    public function listStorages(string $node): array
    {
        $this->record(__FUNCTION__, [$node]);

        return $this->storages;
    }

    public function listVms(string $node): array
    {
        $this->record(__FUNCTION__, [$node]);

        return array_values($this->vms);
    }

    public function getVm(string $node, int $vmid): ?VmSummary
    {
        $this->record(__FUNCTION__, [$node, $vmid]);

        return $this->vms[$vmid] ?? null;
    }

    public function nextFreeVmid(): int
    {
        $this->record(__FUNCTION__, []);

        while (isset($this->vms[$this->nextVmid])) {
            $this->nextVmid++;
        }

        return $this->nextVmid;
    }

    public function createVm(CreateVmSpec $spec): void
    {
        $this->record(__FUNCTION__, [$spec->vmid]);

        if (isset($this->vms[$spec->vmid])) {
            throw HypervisorException::vmidInUse($spec->vmid);
        }

        $this->specs[$spec->vmid] = $spec;
        $this->vms[$spec->vmid] = new VmSummary($spec->vmid, $spec->name, VmPowerState::Stopped, $spec->cores, $spec->memoryMb * 1024 * 1024, $spec->diskSizeGb * 1024 ** 3, $spec->tags);

        if ($this->failAfterCreate) {
            throw HypervisorException::taskFailed('fake failure after the VM was allocated (resize task)');
        }
    }

    public function importDisk(string $node, int $vmid, string $disk, string $storage, string $volume): void
    {
        $this->record(__FUNCTION__, [$node, $vmid, $disk, $storage, $volume]);
    }

    public function attachDisk(string $node, int $vmid, string $disk, string $value): void
    {
        $this->record(__FUNCTION__, [$node, $vmid, $disk]);
    }

    public function resizeDisk(string $node, int $vmid, string $disk, int $sizeGb): void
    {
        $this->record(__FUNCTION__, [$node, $vmid, $disk, $sizeGb]);
    }

    public function startVm(string $node, int $vmid): void
    {
        $this->record(__FUNCTION__, [$node, $vmid]);
        $this->setStatus($vmid, VmPowerState::Running);
    }

    public function stopVm(string $node, int $vmid): void
    {
        $this->record(__FUNCTION__, [$node, $vmid]);
        $this->setStatus($vmid, VmPowerState::Stopped);
    }

    public function deleteVm(string $node, int $vmid, bool $purge = true): void
    {
        $this->record(__FUNCTION__, [$node, $vmid, $purge]);

        if (($this->vms[$vmid]->status ?? null) === VmPowerState::Running) {
            throw HypervisorException::taskFailed('VM is running');
        }

        unset($this->vms[$vmid]);
    }

    public function vmStatus(string $node, int $vmid): VmPowerState
    {
        $this->record(__FUNCTION__, [$node, $vmid]);

        return $this->vms[$vmid]->status ?? throw HypervisorException::notFound("vm {$vmid}");
    }

    public function agentPing(string $node, int $vmid): bool
    {
        $this->record(__FUNCTION__, [$node, $vmid]);

        return $this->agentAnswers && ($this->vms[$vmid]->status ?? null) === VmPowerState::Running;
    }

    public function hasVolume(string $node, string $storage, string $volume): bool
    {
        $this->record(__FUNCTION__, [$node, $storage, $volume]);

        return in_array($volume, $this->volumes, true);
    }

    public function downloadImage(string $node, string $storage, ImageSource $image): string
    {
        $this->record(__FUNCTION__, [$node, $storage, $image->filename]);
        $this->downloads[] = $image->url;
        $this->volumes[] = "{$storage}:import/{$image->filename}";

        return "{$storage}:import/{$image->filename}";
    }

    public function assertCalled(string $method): void
    {
        Assert::assertTrue($this->count($method) > 0, "Hypervisor call [{$method}] was not made.");
    }

    public function assertNotCalled(string $method): void
    {
        Assert::assertSame(0, $this->count($method), "Hypervisor call [{$method}] was made.");
    }

    public function count(string $method): int
    {
        return count(array_filter($this->calls, fn (array $c): bool => $c['method'] === $method));
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];

        if (isset($this->failing[$method])) {
            throw HypervisorException::taskFailed("fake failure in {$method}");
        }
    }

    private function setStatus(int $vmid, VmPowerState $state): void
    {
        $vm = $this->vms[$vmid] ?? throw HypervisorException::notFound("vm {$vmid}");
        $this->vms[$vmid] = new VmSummary($vm->vmid, $vm->name, $state, $vm->cpus, $vm->maxMem, $vm->maxDisk, $vm->tags);
    }
}
