<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Jobs;

use App\Modules\Provisioning\Actions\ExecuteProvisioningRun;
use App\Modules\Provisioning\Models\ProvisioningRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs one provisioning run. Never retried: a half-applied plan must be
 * inspected, not replayed blindly (a new run is idempotent anyway). The
 * per-step budget (`provisioning.steps.timeout`) is enforced cooperatively by
 * the steps; the job timeout is the sum of all steps plus slack.
 */
class RunProvisioningPlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly int $runId)
    {
        $this->timeout = (int) config('provisioning.steps.timeout') * 10 + 300;
    }

    public function handle(ExecuteProvisioningRun $execute): void
    {
        $run = ProvisioningRun::query()->find($this->runId);

        if ($run !== null) {
            $execute->handle($run);
        }
    }
}
