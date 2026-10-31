<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Actions;

use App\Models\User;
use App\Modules\Provisioning\Domain\TestVmConfig;
use App\Modules\Provisioning\Enums\RunStatus;
use App\Modules\Provisioning\Enums\StepStatus;
use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Jobs\RunProvisioningPlanJob;
use App\Modules\Provisioning\Models\Hypervisor;
use App\Modules\Provisioning\Models\ProvisioningRun;
use App\Modules\Provisioning\Support\ProvisioningPlans;
use App\Modules\Provisioning\Support\RunOptions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Creates the run and its step rows and (by default) queues the job. The actor
 * is stored on the run and re-authorized inside the job. Refuses while another
 * run for the same target is pending or running.
 */
class StartProvisioningRun
{
    public function __construct(private readonly ProvisioningPlans $plans) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function handle(string $planKey, array $config, Hypervisor $hypervisor, User $actor, RunOptions $options = new RunOptions, bool $dispatch = true): ProvisioningRun
    {
        Gate::forUser($actor)->authorize('create', ProvisioningRun::class);
        Gate::forUser($actor)->authorize('view', $hypervisor);

        $plan = $this->plans->get($planKey);
        $typed = TestVmConfig::fromArray($config);
        $target = "vm:{$hypervisor->id}:".($typed->node ?? $hypervisor->node).":{$typed->name}";

        try {
            $run = DB::transaction(function () use ($plan, $typed, $hypervisor, $actor, $options, $target): ProvisioningRun {
                $run = new ProvisioningRun;
                $run->forceFill([
                    'plan' => $plan->key,
                    'target_key' => $target,
                    'hypervisor_id' => $hypervisor->id,
                    'requested_by' => $actor->id,
                    'status' => RunStatus::Pending,
                    'options' => $options->toArray(),
                    'context' => ['config' => $typed->toArray(), 'state' => []],
                ])->save();

                foreach ($plan->steps as $position => $step) {
                    $run->steps()->make()->forceFill([
                        'position' => $position,
                        'step' => $step->key(),
                        'status' => StepStatus::Pending,
                    ])->save();
                }

                return $run;
            });
        } catch (UniqueConstraintViolationException) {
            throw ProvisioningException::alreadyRunning($target);
        }

        if ($dispatch) {
            RunProvisioningPlanJob::dispatch($run->id);
        }

        return $run;
    }
}
