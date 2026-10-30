<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Steps;

use App\Modules\Hosts\Actions\PinHostKey;
use App\Modules\Hosts\Actions\ProbeHost;
use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Enums\HostStatus;
use App\Modules\Provisioning\Contracts\HostKeyScanner;
use App\Modules\Provisioning\Contracts\HostKeyTrust;
use App\Modules\Provisioning\Contracts\ProvisioningStep;
use App\Modules\Provisioning\Domain\PlannedAction;
use App\Modules\Provisioning\Domain\StepDescription;
use App\Modules\Provisioning\Domain\VerifyResult;
use App\Modules\Provisioning\Enums\StepOutcome;
use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Support\CrossCheckHostKeyTrust;
use App\Modules\Provisioning\Support\ProvisioningContext;

/**
 * Step 0c: wait for sshd, establish the host key out of band
 * ({@see HostKeyTrust}: direct view must equal the view through the hypervisor
 * host), pin it on the remote host BEFORE the first login, then prove key login
 * works (the pin is enforced by the executor before authentication).
 */
class WaitForSsh implements ProvisioningStep
{
    public function __construct(
        private readonly HostKeyScanner $scanner,
        private readonly HostKeyTrust $trust,
        private readonly PinHostKey $pinHostKey,
        private readonly ProbeHost $probeHost,
        private readonly RemoteExecutor $executor,
    ) {}

    public function key(): string
    {
        return 'wait_for_ssh';
    }

    public function describe(ProvisioningContext $context): StepDescription
    {
        $ip = $context->config->ip;

        return new StepDescription(
            'Wait for SSH and pin the host key out of band',
            [
                PlannedAction::local("poll {$ip}:22 until the VM presents an SSH host key (up to ".config('provisioning.steps.ssh_wait_seconds').' s)'),
                PlannedAction::local("read the host key directly from LANoMAT and through the hypervisor's jump host; they must be equal"),
                PlannedAction::local('on the hypervisor host (independent path), run:'),
                PlannedAction::ssh(CrossCheckHostKeyTrust::keyscanScript($ip, 22)->render()),
                PlannedAction::local('pin the agreed fingerprint on the remote host, then log in with the generated key (fresh connection)'),
            ],
            ['a fresh SSH login with the pinned key works'],
            'nothing (the VM deletion of an earlier step removes the host record)',
        );
    }

    public function apply(ProvisioningContext $context): StepOutcome
    {
        $host = $context->vmHost();

        if ($host->host_fingerprint !== null && $this->executor->probe($host)->reachable) {
            return StepOutcome::AlreadySatisfied;
        }

        $this->waitUntil($context, fn (): bool => $this->scanner->scan($context->config->ip, 22) !== null, 'the VM did not present an SSH host key in time');

        $fingerprint = $this->trust->establish($context->config->ip, 22, $context->hypervisor()->jumpHost);
        $context->log('host key confirmed on two independent paths');

        $this->pinHostKey->handle($host, $fingerprint, $context->actor());
        $context->log('host key pinned');

        $this->waitUntil($context, fn (): bool => $this->probeHost->handle($host->refresh())->status === HostStatus::Reachable, 'key login did not work in time (cloud-init may have failed)');

        return StepOutcome::Applied;
    }

    public function verify(ProvisioningContext $context): VerifyResult
    {
        return $this->executor->probe($context->vmHost())->reachable
            ? VerifyResult::ok()
            : VerifyResult::failed('A fresh SSH login to the VM failed.');
    }

    public function rollback(ProvisioningContext $context): void
    {
        // Nothing to undo: a pinned key on a record whose VM is deleted does no harm.
    }

    /**
     * @param  callable(): bool  $ready
     */
    private function waitUntil(ProvisioningContext $context, callable $ready, string $timeoutMessage): void
    {
        $budget = (int) config('provisioning.steps.ssh_wait_seconds');
        $poll = max(1, (int) config('provisioning.steps.ssh_poll_seconds'));

        for ($waited = 0; $waited <= $budget; $waited += $poll) {
            $context->assertWithinDeadline();

            if ($ready()) {
                return;
            }

            $context->sleep($poll);
        }

        throw ProvisioningException::stepFailed(ucfirst($timeoutMessage).'.');
    }
}
