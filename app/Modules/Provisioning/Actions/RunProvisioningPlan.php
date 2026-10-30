<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Actions;

use App\Modules\Hosts\Exceptions\RemoteExecutionException;
use App\Modules\Provisioning\Contracts\ProvisioningStep;
use App\Modules\Provisioning\Domain\StepDescription;
use App\Modules\Provisioning\Enums\RunStatus;
use App\Modules\Provisioning\Enums\StepOutcome;
use App\Modules\Provisioning\Enums\StepStatus;
use App\Modules\Provisioning\Events\ProvisioningRunFinished;
use App\Modules\Provisioning\Events\ProvisioningStepChanged;
use App\Modules\Provisioning\Exceptions\HypervisorException;
use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Models\ProvisioningRun;
use App\Modules\Provisioning\Models\ProvisioningRunStep;
use App\Modules\Provisioning\Support\ProvisioningContext;
use App\Modules\Provisioning\Support\ProvisioningPlan;
use App\Modules\Provisioning\Support\Redactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a {@see ProvisioningPlan}.
 *
 * - {@see describe()} is the DRY RUN: it renders what each step would do and
 *   performs nothing (no external call, no database write).
 * - {@see handle()} applies the steps in order, persisting status, redacted log
 *   and the context state per step. It stops at the first failure. The failed
 *   step is rolled back automatically (it only undoes what it created); steps
 *   applied earlier are rolled back only when the run was started with
 *   `rollbackAll`. One plan per target runs at a time: a cache lock here plus a
 *   partial unique index on active runs in the database.
 */
class RunProvisioningPlan
{
    /**
     * @return list<StepDescription>
     */
    public function describe(ProvisioningPlan $plan, ProvisioningContext $context): array
    {
        return array_map(fn (ProvisioningStep $step): StepDescription => $step->describe($context), $plan->steps);
    }

    public function handle(ProvisioningRun $run, ProvisioningPlan $plan, ProvisioningContext $context): ProvisioningRun
    {
        $stepTimeout = (int) config('provisioning.steps.timeout');
        $lock = Cache::lock('provisioning:target:'.$run->target_key, $stepTimeout * max(1, count($plan->steps)) + 120);

        if (! $lock->get()) {
            return $this->finish($run, RunStatus::Failed, ProvisioningException::alreadyRunning($run->target_key)->getMessage());
        }

        try {
            return $this->execute($run, $plan, $context, $stepTimeout);
        } finally {
            $lock->release();
        }
    }

    private function execute(ProvisioningRun $run, ProvisioningPlan $plan, ProvisioningContext $context, int $stepTimeout): ProvisioningRun
    {
        $run->forceFill(['status' => RunStatus::Running, 'started_at' => now()])->save();

        /** @var array<string, ProvisioningRunStep> $rows */
        $rows = $run->steps()->get()->keyBy('step')->all();
        $options = $run->options;

        /** @var list<ProvisioningStep> $done steps this run applied (not merely found satisfied) */
        $done = [];

        foreach ($plan->steps as $step) {
            $row = $rows[$step->key()];
            $this->mark($row, StepStatus::Running, ['started_at' => now()]);

            try {
                $context->startStep($stepTimeout);
                $outcome = $step->apply($context);
                $this->persistState($run, $context);
                $this->mark($row, StepStatus::Applied, ['skipped' => $outcome === StepOutcome::AlreadySatisfied]);

                $verify = $step->verify($context);

                if (! $verify->ok) {
                    throw ProvisioningException::verificationFailed($verify->message);
                }

                $this->mark($row, StepStatus::Verified, ['finished_at' => now(), 'log' => $this->log($context)]);

                if ($outcome === StepOutcome::Applied) {
                    $done[] = $step;
                }
            } catch (Throwable $e) {
                return $this->fail($run, $context, $row, $step, $done, $options, $e);
            }
        }

        return $this->finish($run, RunStatus::Succeeded, null);
    }

    /**
     * @param  list<ProvisioningStep>  $done
     * @param  array<string, mixed>  $options
     */
    private function fail(ProvisioningRun $run, ProvisioningContext $context, ProvisioningRunStep $row, ProvisioningStep $step, array $done, array $options, Throwable $e): ProvisioningRun
    {
        $message = $this->safeMessage($e, $context);
        $context->log('FAILED: '.$message);
        $this->persistState($run, $context);

        $rolledBackAny = false;

        $this->mark($row, StepStatus::Failed, ['finished_at' => now(), 'error' => $message, 'log' => $this->log($context)]);

        if (($options['rollback_failed_step'] ?? true) === true && $this->rollbackStep($step, $context)) {
            $this->mark($row, StepStatus::RolledBack, ['log' => $this->log($context)]);
        }

        if (($options['rollback_all'] ?? false) === true) {
            foreach (array_reverse($done) as $earlier) {
                $earlierRow = $run->steps()->where('step', $earlier->key())->firstOrFail();

                if ($this->rollbackStep($earlier, $context)) {
                    $rolledBackAny = true;
                    $this->mark($earlierRow, StepStatus::RolledBack, ['log' => $this->log($context, $earlierRow->log)]);
                }
            }

            $this->persistState($run, $context);
        }

        return $this->finish($run, ($options['rollback_all'] ?? false) === true && $rolledBackAny ? RunStatus::RolledBack : RunStatus::Failed, $message);
    }

    private function rollbackStep(ProvisioningStep $step, ProvisioningContext $context): bool
    {
        try {
            $context->log("Rolling back step [{$step->key()}].");
            $step->rollback($context);

            return true;
        } catch (Throwable $e) {
            $context->log('Rollback failed: '.$this->safeMessage($e, $context));

            return false;
        }
    }

    /**
     * Domain exceptions carry safe English messages; anything else only shows
     * its class so a library message that happens to embed a secret never lands in the database.
     */
    private function safeMessage(Throwable $e, ProvisioningContext $context): string
    {
        if ($e instanceof ProvisioningException || $e instanceof HypervisorException || $e instanceof RemoteExecutionException) {
            return Redactor::redact($e->getMessage(), $context->secrets());
        }

        Log::warning('Provisioning step raised an unexpected exception.', ['exception' => $e::class]);

        return 'Unexpected error ('.class_basename($e).').';
    }

    private function log(ProvisioningContext $context, ?string $previous = null): string
    {
        return Redactor::truncate(($previous === null ? '' : $previous."\n").$context->flushLog(), (int) config('provisioning.log_limit'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function mark(ProvisioningRunStep $row, StepStatus $status, array $attributes = []): void
    {
        $row->forceFill($attributes + ['status' => $status])->save();
        ProvisioningStepChanged::dispatch((int) $row->provisioning_run_id, $row->step, $status->value);
    }

    private function persistState(ProvisioningRun $run, ProvisioningContext $context): void
    {
        $run->forceFill(['context' => ['config' => $run->context['config'], 'state' => $context->state()]])->save();
    }

    private function finish(ProvisioningRun $run, RunStatus $status, ?string $error): ProvisioningRun
    {
        $run->forceFill(['status' => $status, 'error' => $error, 'finished_at' => now()])->save();
        ProvisioningRunFinished::dispatch($run->id, $status->value);

        return $run;
    }
}
