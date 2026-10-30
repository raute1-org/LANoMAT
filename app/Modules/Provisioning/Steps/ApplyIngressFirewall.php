<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Steps;

use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Provisioning\Contracts\ProvisioningStep;
use App\Modules\Provisioning\Domain\FirewallPolicy;
use App\Modules\Provisioning\Domain\PlannedAction;
use App\Modules\Provisioning\Domain\StepDescription;
use App\Modules\Provisioning\Domain\VerifyResult;
use App\Modules\Provisioning\Enums\StepOutcome;
use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Support\ProvisioningContext;
use App\Modules\Provisioning\Support\ProvisioningScript;
use App\Modules\Provisioning\Support\VmShell;

/**
 * Steps 1c and 2 of the runbook: ufw (SSH from the allowed sources) and the
 * DOCKER-USER block in /etc/ufw/after.rules, both rendered by
 * {@see FirewallPolicy} from one source list. The block between the LANoMAT
 * markers is replaced as a whole, so re-running is safe.
 *
 * Lock-out guards: the step refuses to run when the address LANoMAT connects
 * from (`$SSH_CLIENT`) is not covered by the allowed sources, and it only
 * stays enabled after a fresh SSH session worked.
 */
class ApplyIngressFirewall implements ProvisioningStep
{
    private const string BLOCK_FILE = '/etc/ufw/lanomat-docker-user.block';

    public function __construct(private readonly RemoteExecutor $executor) {}

    public function key(): string
    {
        return 'ingress_firewall';
    }

    public function describe(ProvisioningContext $context): StepDescription
    {
        $policy = $context->config->firewall();

        return new StepDescription(
            'Ingress firewall: ufw and the DOCKER-USER chain',
            [
                PlannedAction::local('refuse to continue (lock-out guard) when the address LANoMAT connects from is not in '.implode(', ', $policy->allowedSourceCidrs)),
                PlannedAction::ssh($this->sourceGuard()->render()),
                PlannedAction::ssh($this->check($policy)->render()),
                ...VmShell::installActions($policy->dockerUserBlock(), self::BLOCK_FILE, '0640'),
                PlannedAction::ssh($this->applyScript($policy)->render()),
                PlannedAction::local('open a second, fresh SSH session; on failure disable ufw and remove the block again'),
            ],
            ['ufw is active', 'DOCKER-USER lists every allowed source and the final DROP'],
            'ufw disable, remove the managed block from after.rules, reload',
        );
    }

    public function apply(ProvisioningContext $context): StepOutcome
    {
        $policy = $context->config->firewall();
        $shell = $this->shell($context);

        $client = trim($shell->output($this->sourceGuard()));

        if (! $policy->allowsSource($client)) {
            throw ProvisioningException::lockedOut('The address LANoMAT connects from is not covered by allowed_source_cidrs; enabling ufw would lock the automation out.');
        }

        if ($shell->succeeds($this->check($policy))) {
            return StepOutcome::AlreadySatisfied;
        }

        $shell->install($policy->dockerUserBlock(), self::BLOCK_FILE, '0640');
        $context->put('ingress_firewall_applied', true);
        $shell->run($this->applyScript($policy));

        if (! $this->executor->probe($context->vmHost())->reachable) {
            $shell->run($this->remove());
            $context->put('ingress_firewall_applied', false);

            throw ProvisioningException::lockedOut('A fresh SSH session failed after enabling ufw; the firewall was disabled again.');
        }

        $context->log('second SSH session works with ufw enabled');

        return StepOutcome::Applied;
    }

    public function verify(ProvisioningContext $context): VerifyResult
    {
        $policy = $context->config->firewall();
        $shell = $this->shell($context);

        if (! $shell->succeeds(ProvisioningScript::make('ufw-active', "sudo ufw status | grep -q '^Status: active'"))) {
            return VerifyResult::failed('ufw is not active.');
        }

        foreach ($policy->allowedSourceCidrs as $cidr) {
            if (! $shell->succeeds(ProvisioningScript::make('docker-user-source', 'sudo iptables -S DOCKER-USER | grep -qF -- {{CIDR}}', ['CIDR' => $cidr]))) {
                return VerifyResult::failed("DOCKER-USER has no rule for {$cidr}.");
            }
        }

        return $shell->succeeds(ProvisioningScript::make('docker-user-drop', "sudo iptables -S DOCKER-USER | grep -q -- '-j DROP'"))
            ? VerifyResult::ok()
            : VerifyResult::failed('DOCKER-USER has no final DROP rule.');
    }

    public function rollback(ProvisioningContext $context): void
    {
        if ($context->flag('ingress_firewall_applied')) {
            $this->shell($context)->run($this->remove());
            $context->put('ingress_firewall_applied', false);
        }
    }

    private function shell(ProvisioningContext $context): VmShell
    {
        return new VmShell($this->executor, $context, $context->vmHost());
    }

    private function sourceGuard(): ProvisioningScript
    {
        return ProvisioningScript::make('ssh-client-address', 'echo "${SSH_CLIENT%% *}"');
    }

    private function check(FirewallPolicy $policy): ProvisioningScript
    {
        return ProvisioningScript::make('ingress-check', <<<'SH'
[ "$(sudo sed -n '/^# >>> LANoMAT docker-user >>>$/,/^# <<< LANoMAT docker-user <<<$/p' /etc/ufw/after.rules | sha256sum | cut -d' ' -f1)" = {{HASH}} ]
sudo ufw status | grep -q '^Status: active'
SH, ['HASH' => hash('sha256', $policy->dockerUserBlock())]);
    }

    private function applyScript(FirewallPolicy $policy): ProvisioningScript
    {
        $ufw = implode("\n", array_map(fn (string $c): string => 'sudo '.$c, $policy->ufwCommands()));

        return ProvisioningScript::make('ingress-apply', <<<SH
[ -f /root/after.rules.orig ] || sudo cp /etc/ufw/after.rules /root/after.rules.orig
sudo sed -i '/^# >>> LANoMAT docker-user >>>\$/,/^# <<< LANoMAT docker-user <<<\$/d' /etc/ufw/after.rules
{ echo; sudo cat /etc/ufw/lanomat-docker-user.block; } | sudo tee -a /etc/ufw/after.rules >/dev/null
{$ufw}
sudo ufw reload
SH);
    }

    private function remove(): ProvisioningScript
    {
        return ProvisioningScript::make('ingress-remove', <<<'SH'
sudo ufw --force disable
sudo sed -i '/^# >>> LANoMAT docker-user >>>$/,/^# <<< LANoMAT docker-user <<<$/d' /etc/ufw/after.rules
sudo rm -f /etc/ufw/lanomat-docker-user.block
SH);
    }
}
