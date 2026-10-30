<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Steps;

use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Lancache\Actions\ApplyLancacheStack;
use App\Modules\Lancache\Actions\VerifyLancacheStack;
use App\Modules\Lancache\Domain\LancacheConfig;
use App\Modules\Lancache\Support\LancacheStack;
use App\Modules\Provisioning\Contracts\ProvisioningStep;
use App\Modules\Provisioning\Domain\PlannedAction;
use App\Modules\Provisioning\Domain\StepDescription;
use App\Modules\Provisioning\Domain\VerifyResult;
use App\Modules\Provisioning\Enums\StepOutcome;
use App\Modules\Provisioning\Support\ProvisioningContext;
use Throwable;

/**
 * Step 3 of the runbook: the LanCache compose stack, applied through the
 * Lancache module's {@see ApplyLancacheStack} (typed {@see LancacheConfig},
 * ports bound to the VM address only) and checked with
 * {@see VerifyLancacheStack}.
 */
class ApplyLancache implements ProvisioningStep
{
    public function __construct(
        private readonly ApplyLancacheStack $apply,
        private readonly VerifyLancacheStack $verify,
        private readonly RemoteExecutor $executor,
    ) {}

    public function key(): string
    {
        return 'lancache';
    }

    public function describe(ProvisioningContext $context): StepDescription
    {
        $c = $context->config->lancache();

        return new StepDescription(
            'LanCache stack (lancachenet monolithic + lancache-dns)',
            [
                PlannedAction::local('skip everything below when files and containers already match:'),
                PlannedAction::ssh(LancacheStack::satisfied($c)),
                PlannedAction::ssh(LancacheStack::resolverCheck($c)),
                PlannedAction::ssh(LancacheStack::prepare($c)),
                PlannedAction::upload(LancacheStack::tmpPath(LancacheStack::ENV_FILE), $c->envFile()),
                PlannedAction::ssh(LancacheStack::install(LancacheStack::ENV_FILE, '0640')),
                PlannedAction::upload(LancacheStack::tmpPath(LancacheStack::COMPOSE_FILE), $c->composeFile()),
                PlannedAction::ssh(LancacheStack::install(LancacheStack::COMPOSE_FILE, '0644')),
                PlannedAction::ssh(LancacheStack::up()),
            ],
            ['both containers run with matching files', 'ports 53/80/443 are bound to the cache IP only', 'the heartbeat answers 204'],
            'docker compose down (cache data stays in the cache root)',
        );
    }

    public function apply(ProvisioningContext $context): StepOutcome
    {
        try {
            $applied = $this->apply->handle($context->vmHost(), $context->config->lancache(), $context->actor());
        } catch (Throwable $e) {
            // The stack may be half applied: let the rollback bring it down.
            $context->put('lancache_applied', true);

            throw $e;
        }

        if (! $applied) {
            return StepOutcome::AlreadySatisfied;
        }

        $context->put('lancache_applied', true);
        $context->log('lancache stack applied');

        return StepOutcome::Applied;
    }

    public function verify(ProvisioningContext $context): VerifyResult
    {
        $failures = $this->verify->handle($context->vmHost(), $context->config->lancache(), $context->actor());

        return $failures === [] ? VerifyResult::ok() : VerifyResult::failed(implode(' ', $failures));
    }

    public function rollback(ProvisioningContext $context): void
    {
        if ($context->flag('lancache_applied')) {
            $this->executor->run($context->vmHost(), 'bash -c '.escapeshellarg("set -euo pipefail\n".LancacheStack::down()));
            $context->put('lancache_applied', false);
        }
    }
}
