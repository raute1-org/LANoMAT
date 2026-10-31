<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Provisioning\Actions\ExecuteProvisioningRun;
use App\Modules\Provisioning\Actions\StartProvisioningRun;
use App\Modules\Provisioning\Console\Concerns\GuardsProvisioning;
use App\Modules\Provisioning\Enums\RunStatus;
use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Support\RunOptions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;

/**
 * Executes a plan for real. Refuses without `--confirm`. By default the run is
 * queued (a worker must be running); `--sync` runs it inline and prints the
 * per-step result.
 */
class RunCommand extends Command
{
    use GuardsProvisioning;

    protected $signature = 'provisioning:run
        {plan=test-vm-lancache : Plan-Schlüssel}
        {--config= : JSON-Datei mit der Plan-Konfiguration}
        {--hypervisor= : Name des Hypervisors}
        {--as= : ID des ausführenden Orga-/Admin-Benutzers}
        {--confirm : Ausdrückliche Bestätigung; ohne dieses Flag wird nichts ausgeführt}
        {--rollback : Bei einem Fehler auch die zuvor angewendeten Schritte zurückbauen (sonst nur der fehlgeschlagene Schritt)}
        {--sync : Sofort im Vordergrund ausführen statt über die Queue}';

    protected $description = 'Provisioning-Plan ausführen (legt VMs an und konfiguriert sie). Erfordert --confirm; vorher provisioning:plan ansehen.';

    public function handle(StartProvisioningRun $start, ExecuteProvisioningRun $execute): int
    {
        if (! $this->moduleEnabled()) {
            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->error(__('provisioning.errors.missing_confirmation'));
            $this->line(__('provisioning.console.run_hint'));

            return self::FAILURE;
        }

        $data = $this->readConfig($this->stringOption('config'));
        $hypervisor = $this->findHypervisor($this->stringOption('hypervisor'));
        $actor = $this->actor($this->option('as'));

        if ($data === null || $hypervisor === null || $actor === null) {
            if ($hypervisor === null && $this->stringOption('hypervisor') === null) {
                $this->error(__('provisioning.console.hypervisor_required'));
            }

            return self::FAILURE;
        }

        try {
            $run = $start->handle($this->stringArgument('plan'), $data, $hypervisor, $actor, new RunOptions(rollbackAll: (bool) $this->option('rollback')), dispatch: ! $this->option('sync'));

            if (! $this->option('sync')) {
                $this->info(__('provisioning.console.run_queued', ['id' => $run->id]));

                return self::SUCCESS;
            }

            $run = $execute->handle($run);
        } catch (ProvisioningException $e) {
            $this->printError($e);

            return self::FAILURE;
        } catch (AuthorizationException) {
            $this->error(__('provisioning.console.not_authorized'));

            return self::FAILURE;
        }

        foreach ($run->steps as $step) {
            $this->line(sprintf('%-18s %s%s', $step->step, $step->status->value, $step->skipped ? ' ('.__('provisioning.console.already_satisfied').')' : ''));
        }

        if ($run->status === RunStatus::Succeeded) {
            $this->info(__('provisioning.console.run_succeeded', ['id' => $run->id]));

            return self::SUCCESS;
        }

        $this->error(__('provisioning.console.run_failed', ['id' => $run->id, 'status' => $run->status->value]));
        $this->line((string) $run->error);

        return self::FAILURE;
    }
}
