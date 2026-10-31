<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Actions;

use App\Modules\Provisioning\Domain\TestVmConfig;
use App\Modules\Provisioning\Enums\RunStatus;
use App\Modules\Provisioning\Models\ProvisioningRun;
use App\Modules\Provisioning\Support\ProvisioningContext;
use App\Modules\Provisioning\Support\ProvisioningPlans;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Closure;
use Illuminate\Support\Facades\Gate;

/**
 * Executes a pending run (from the queue job or `provisioning:run --sync`):
 * re-authorizes the stored actor, rebuilds the context from the encrypted
 * run record and hands over to {@see RunProvisioningPlan}.
 */
class ExecuteProvisioningRun
{
    public function __construct(
        private readonly ProvisioningPlans $plans,
        private readonly RunProvisioningPlan $runner,
    ) {}

    /**
     * @param  (Closure(int): void)|null  $sleeper
     */
    public function handle(ProvisioningRun $run, ?Closure $sleeper = null): ProvisioningRun
    {
        if ($run->status !== RunStatus::Pending) {
            return $run;
        }

        if (! app(Modules::class)->enabled(Module::Provisioning)) {
            // Without the module no policy is registered and Gate::before would let an admin through.
            $run->forceFill(['status' => RunStatus::Failed, 'error' => 'The provisioning module is disabled.', 'finished_at' => now()])->save();

            return $run;
        }

        $actor = $run->requester;

        if ($actor === null || Gate::forUser($actor)->denies('create', ProvisioningRun::class)) {
            $run->forceFill(['status' => RunStatus::Failed, 'error' => 'The requesting user is no longer allowed to provision.', 'finished_at' => now()])->save();

            return $run;
        }

        $context = new ProvisioningContext(
            config: TestVmConfig::fromArray($run->context['config']),
            hypervisor: $run->hypervisor,
            actor: $actor,
            dryRun: false,
            state: $run->context['state'],
            sleeper: $sleeper,
        );

        return $this->runner->handle($run, $this->plans->get($run->plan), $context);
    }
}
