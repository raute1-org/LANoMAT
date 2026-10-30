<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Steps;

use App\Modules\Provisioning\Contracts\HypervisorClientFactory;
use App\Modules\Provisioning\Contracts\ProvisioningStep;
use App\Modules\Provisioning\Domain\PlannedAction;
use App\Modules\Provisioning\Domain\StepDescription;
use App\Modules\Provisioning\Domain\VerifyResult;
use App\Modules\Provisioning\Enums\StepOutcome;
use App\Modules\Provisioning\Enums\VmPowerState;
use App\Modules\Provisioning\Support\ProvisioningContext;

class StartVm implements ProvisioningStep
{
    public function __construct(private readonly HypervisorClientFactory $clients) {}

    public function key(): string
    {
        return 'start_vm';
    }

    public function describe(ProvisioningContext $context): StepDescription
    {
        return new StepDescription(
            'Start the VM',
            [PlannedAction::hypervisor("start VM {$context->vmidLabel()} on node {$context->node()} (skipped when already running)")],
            ['the VM is running'],
            'stop the VM, only when this run started it',
        );
    }

    public function apply(ProvisioningContext $context): StepOutcome
    {
        $client = $this->clients->for($context->hypervisor());

        if ($client->vmStatus($context->node(), (int) $context->vmid()) === VmPowerState::Running) {
            return StepOutcome::AlreadySatisfied;
        }

        $client->startVm($context->node(), (int) $context->vmid());
        $context->put('vm_started', true);
        $context->log('VM started');

        return StepOutcome::Applied;
    }

    public function verify(ProvisioningContext $context): VerifyResult
    {
        return $this->clients->for($context->hypervisor())->vmStatus($context->node(), (int) $context->vmid()) === VmPowerState::Running
            ? VerifyResult::ok()
            : VerifyResult::failed('The VM is not running.');
    }

    public function rollback(ProvisioningContext $context): void
    {
        if ($context->flag('vm_started')) {
            $this->clients->for($context->hypervisor())->stopVm($context->node(), (int) $context->vmid());
            $context->put('vm_started', false);
        }
    }
}
