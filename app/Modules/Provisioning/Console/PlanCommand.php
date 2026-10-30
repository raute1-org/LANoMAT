<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Provisioning\Actions\RunProvisioningPlan;
use App\Modules\Provisioning\Console\Concerns\GuardsProvisioning;
use App\Modules\Provisioning\Domain\TestVmConfig;
use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Support\ProvisioningContext;
use App\Modules\Provisioning\Support\ProvisioningPlans;
use Illuminate\Console\Command;

/**
 * The DRY RUN: renders what the plan would do. No external call, no database
 * write, nothing is executed. Safe to run anywhere.
 */
class PlanCommand extends Command
{
    use GuardsProvisioning;

    protected $signature = 'provisioning:plan
        {plan=test-vm-lancache : Plan-Schlüssel}
        {--config= : JSON-Datei mit der Plan-Konfiguration}
        {--hypervisor= : Optional: Name des Hypervisors (liefert den Standard-Node)}';

    protected $description = 'Trockenlauf: zeigt lesbar, was ein Provisioning-Plan tun würde (führt nichts aus).';

    public function handle(ProvisioningPlans $plans, RunProvisioningPlan $runner): int
    {
        if (! $this->moduleEnabled()) {
            return self::FAILURE;
        }

        $data = $this->readConfig($this->option('config'));
        $name = $this->option('hypervisor');
        $hypervisor = is_string($name) ? $this->findHypervisor($name) : null;

        if ($data === null || (is_string($name) && $hypervisor === null)) {
            return self::FAILURE;
        }

        try {
            $plan = $plans->get((string) $this->argument('plan'));
            $context = new ProvisioningContext(TestVmConfig::fromArray($data), $hypervisor, null, dryRun: true);
            $descriptions = $runner->describe($plan, $context);
        } catch (ProvisioningException $e) {
            $this->printError($e);

            return self::FAILURE;
        }

        $this->info(__('provisioning.console.plan_header', ['label' => $plan->label, 'key' => $plan->key]));
        $this->warn(__('provisioning.console.plan_dry_run'));

        foreach ($descriptions as $i => $step) {
            $this->newLine();
            $this->line('<options=bold>'.($i + 1).'. '.$step->title.'</> ['.$plan->steps[$i]->key().']');

            foreach ($step->actions as $action) {
                $lines = explode("\n", rtrim($action->text));
                $this->line('   <fg=cyan>'.$action->kind.'</> '.array_shift($lines));

                foreach ($lines as $line) {
                    $this->line('       '.$line);
                }
            }

            foreach ($step->verifies as $check) {
                $this->line('   <fg=green>'.__('provisioning.console.verify').'</> '.$check);
            }

            if ($step->rollback !== null) {
                $this->line('   <fg=yellow>'.__('provisioning.console.rollback').'</> '.$step->rollback);
            }
        }

        $this->newLine();
        $this->info(__('provisioning.console.plan_footer'));

        return self::SUCCESS;
    }
}
