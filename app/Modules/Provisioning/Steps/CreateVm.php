<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Steps;

use App\Modules\Hosts\Actions\RegisterHost;
use App\Modules\Hosts\Enums\HostRole;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Provisioning\Contracts\HypervisorClientFactory;
use App\Modules\Provisioning\Contracts\ProvisioningStep;
use App\Modules\Provisioning\Domain\CloudInitSpec;
use App\Modules\Provisioning\Domain\CreateVmSpec;
use App\Modules\Provisioning\Domain\PlannedAction;
use App\Modules\Provisioning\Domain\StepDescription;
use App\Modules\Provisioning\Domain\VerifyResult;
use App\Modules\Provisioning\Domain\VmSummary;
use App\Modules\Provisioning\Enums\StepOutcome;
use App\Modules\Provisioning\Enums\VmPowerState;
use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Support\ProvisioningContext;
use phpseclib3\Crypt\EC;

/**
 * Step 0b: create the VM from the imported image with cloud-init, and register
 * it as a RemoteHost. A fresh ed25519 key pair is generated for the run: the
 * public half goes into cloud-init next to the admin keys, the private half
 * goes only into the RemoteHost (encrypted cast), never into the run context,
 * a log or a command line.
 *
 * Idempotent: an existing VM with this name and the `lanomat` tag is adopted
 * (skip); any other VM on that id is an error, never overwritten.
 */
class CreateVm implements ProvisioningStep
{
    public function __construct(
        private readonly HypervisorClientFactory $clients,
        private readonly RegisterHost $registerHost,
    ) {}

    public function key(): string
    {
        return 'create_vm';
    }

    public function describe(ProvisioningContext $context): StepDescription
    {
        $c = $context->config;
        $spec = $this->spec($context, 'ssh-ed25519 <generated public key>', "{$c->imageStorage}:import/{$c->image->filename}");

        return new StepDescription(
            'Create the VM with cloud-init and register it as a remote host',
            [
                PlannedAction::local('generate a fresh ed25519 key pair for this VM (private key stored encrypted on the remote host record only)'),
                PlannedAction::hypervisor('pick a free VM id'.($c->vmid === null ? '' : " (configured: {$c->vmid})").'; refuse when the id is taken by another VM'),
                PlannedAction::hypervisor(sprintf(
                    'create VM %s (id %s) on node %s: %d MiB RAM (balloon %d), %d cores, cpu %s, %s/%s, bridge %s, %s, agent on, serial0, onboot; scsi0 imported from %s on %s and resized to %d GiB; efidisk, cloud-init drive',
                    $spec->name, $context->vmidLabel(), $spec->node, $spec->memoryMb, $spec->balloonMb, $spec->cores, $spec->cpu, $spec->machine, $spec->bios, $spec->bridge, $spec->scsihw, $spec->importFromVolume, $spec->storage, $spec->diskSizeGb,
                )),
                PlannedAction::hypervisor(sprintf('cloud-init: user %s, %d admin key(s) + the generated key, ip %s/%d gw %s, nameserver %s%s', $c->ciUser, count($c->adminSshKeys), $c->ip, $c->prefix, $c->gateway, $c->nameserver, $c->searchDomain === null ? '' : ", search {$c->searchDomain}")),
                PlannedAction::local("register remote host [{$c->name}] at {$c->ip}:22 (role lancache, user {$c->ciUser}); host key is pinned later, out of band"),
            ],
            ['the VM exists on the hypervisor under the expected name'],
            'stop and delete the VM (purge) and remove the remote host record, only when this run created them',
        );
    }

    public function apply(ProvisioningContext $context): StepOutcome
    {
        $client = $this->clients->for($context->hypervisor());
        $node = $context->node();
        $c = $context->config;

        $vmid = $context->vmid()
            ?? $this->existingVmid($client->listVms($node), $c->name)
            ?? $client->nextFreeVmid();
        $context->put('vmid', $vmid);

        $existing = $client->getVm($node, $vmid);

        if ($existing !== null) {
            if ($existing->name === $c->name && in_array('lanomat', $existing->tags, true)) {
                $hostId = RemoteHost::query()->where('name', $c->name)->value('id');

                if (! is_int($hostId)) {
                    throw ProvisioningException::stepFailed("VM {$vmid} was created by LANoMAT but its remote host record (with the SSH key) is gone; delete the VM and run again.");
                }

                $context->put('host_id', $hostId);
                $context->log("VM {$vmid} already exists, adopting it");

                return StepOutcome::AlreadySatisfied;
            }

            throw ProvisioningException::stepFailed("VM id {$vmid} is already used by another machine; refusing to touch it.");
        }

        if (RemoteHost::query()->where('name', $c->name)->orWhere('hostname', $c->ip)->exists()) {
            throw ProvisioningException::stepFailed("A remote host named [{$c->name}] or at {$c->ip} already exists.");
        }

        $key = EC::createKey('Ed25519');
        $publicKey = trim($key->getPublicKey()->toString('OpenSSH', ['comment' => 'lanomat-provisioning']));
        $privateKey = $key->toString('OpenSSH');
        $context->registerSecret($privateKey);

        $client->createVm($this->spec($context, $publicKey, $context->get('image_volume') ?? "{$c->imageStorage}:import/{$c->image->filename}"));
        $context->put('vm_created', true);
        $context->log("created VM {$vmid}");

        $host = $this->registerHost->handle([
            'name' => $c->name,
            'hostname' => $c->ip,
            'ssh_port' => 22,
            'ssh_user' => $c->ciUser,
            'role' => HostRole::Lancache->value,
            'event_id' => null,
        ], $privateKey, $context->actor());

        $context->put('host_id', $host->id);
        $context->put('host_created', true);
        $context->log("registered remote host {$host->id}");

        return StepOutcome::Applied;
    }

    public function verify(ProvisioningContext $context): VerifyResult
    {
        $vm = $this->clients->for($context->hypervisor())->getVm($context->node(), (int) $context->vmid());

        return $vm !== null && $vm->name === $context->config->name
            ? VerifyResult::ok()
            : VerifyResult::failed('The VM does not exist under the expected name.');
    }

    public function rollback(ProvisioningContext $context): void
    {
        if ($context->flag('host_created') && is_int($context->get('host_id'))) {
            RemoteHost::query()->whereKey($context->get('host_id'))->delete();
            $context->put('host_created', false);
            $context->log('removed the remote host record');
        }

        if (! $context->flag('vm_created') || $context->vmid() === null) {
            return;
        }

        $client = $this->clients->for($context->hypervisor());
        $node = $context->node();
        $vmid = (int) $context->vmid();

        if ($client->getVm($node, $vmid) === null) {
            return;
        }

        if ($client->vmStatus($node, $vmid) !== VmPowerState::Stopped) {
            $client->stopVm($node, $vmid);
        }

        $client->deleteVm($node, $vmid, purge: true);
        $context->put('vm_created', false);
        $context->log("deleted VM {$vmid}");
    }

    /**
     * @param  list<VmSummary>  $vms
     */
    private function existingVmid(array $vms, string $name): ?int
    {
        foreach ($vms as $vm) {
            if ($vm->name === $name && in_array('lanomat', $vm->tags, true)) {
                return $vm->vmid;
            }
        }

        return null;
    }

    private function spec(ProvisioningContext $context, string $generatedPublicKey, string $volume): CreateVmSpec
    {
        $c = $context->config;

        return new CreateVmSpec(
            node: $context->node(),
            vmid: $context->vmid() ?? 0,
            name: $c->name,
            memoryMb: $c->memoryMb,
            balloonMb: 0,
            cores: $c->cores,
            cpu: 'host',
            machine: 'q35',
            bios: 'ovmf',
            bridge: $c->bridge,
            scsihw: 'virtio-scsi-single',
            agent: true,
            serial: true,
            onboot: true,
            storage: $c->storage,
            diskSizeGb: $c->diskGb,
            importFromVolume: $volume,
            cloudInit: new CloudInitSpec($c->ciUser, [...$c->adminSshKeys, $generatedPublicKey], $c->ip, $c->prefix, $c->gateway, $c->nameserver, $c->searchDomain),
        );
    }
}
