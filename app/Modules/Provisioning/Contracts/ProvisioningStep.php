<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Contracts;

use App\Modules\Provisioning\Domain\StepDescription;
use App\Modules\Provisioning\Domain\VerifyResult;
use App\Modules\Provisioning\Enums\StepOutcome;
use App\Modules\Provisioning\Support\ProvisioningContext;
use App\Modules\Provisioning\Support\ProvisioningPlan;
use Throwable;

/**
 * One idempotent unit of a {@see ProvisioningPlan}.
 *
 * - `apply()` checks the current state first and returns
 *   {@see StepOutcome::AlreadySatisfied} without touching anything when the goal
 *   is already met. Remote work goes ONLY through the RemoteExecutor /
 *   HypervisorClient contracts.
 * - `verify()` is read-only and runs after `apply()` (and may be reused by probes).
 * - `rollback()` undoes only what this run did (state flags in the context),
 *   never something that existed before.
 * - `describe()` has no side effects and no external calls: it renders what
 *   `apply()` would do (the dry run).
 */
interface ProvisioningStep
{
    public function key(): string;

    public function describe(ProvisioningContext $context): StepDescription;

    /** @throws Throwable */
    public function apply(ProvisioningContext $context): StepOutcome;

    public function verify(ProvisioningContext $context): VerifyResult;

    public function rollback(ProvisioningContext $context): void;
}
