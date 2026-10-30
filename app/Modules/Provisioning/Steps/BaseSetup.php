<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Steps;

use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Provisioning\Contracts\HypervisorClientFactory;
use App\Modules\Provisioning\Contracts\ProvisioningStep;
use App\Modules\Provisioning\Domain\PlannedAction;
use App\Modules\Provisioning\Domain\StepDescription;
use App\Modules\Provisioning\Domain\VerifyResult;
use App\Modules\Provisioning\Enums\StepOutcome;
use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Support\ProvisioningContext;
use App\Modules\Provisioning\Support\ProvisioningScript;
use App\Modules\Provisioning\Support\VmShell;

/**
 * Step 1 of the runbook: packages (guest agent, chrony, unattended-upgrades,
 * ufw, ...), timezone and the sshd hardening drop-in. The drop-in is only
 * kept after a SECOND, fresh SSH session proved that login still works
 * (`RemoteExecutor::probe` always opens a new connection); otherwise it is
 * removed again right away.
 */
class BaseSetup implements ProvisioningStep
{
    private const string DROP_IN = '/etc/ssh/sshd_config.d/10-hardening.conf';

    private const string HARDENING = "PasswordAuthentication no\nKbdInteractiveAuthentication no\nPermitRootLogin no\n";

    public function __construct(
        private readonly RemoteExecutor $executor,
        private readonly HypervisorClientFactory $clients,
    ) {}

    public function key(): string
    {
        return 'base_setup';
    }

    public function describe(ProvisioningContext $context): StepDescription
    {
        return new StepDescription(
            'Base system: packages, guest agent, timezone, sshd hardening',
            [
                PlannedAction::ssh($this->check($context)->render()),
                PlannedAction::ssh($this->packages($context)->render()),
                ...VmShell::installActions(self::HARDENING, self::DROP_IN),
                PlannedAction::ssh($this->reloadSsh()->render()),
                PlannedAction::local('open a second, fresh SSH session; on failure remove the drop-in and reload sshd again'),
            ],
            ['sshd -T shows password login, keyboard-interactive and root login off', 'the guest agent answers a ping through the hypervisor'],
            'remove the sshd drop-in and reload sshd (packages stay installed)',
        );
    }

    public function apply(ProvisioningContext $context): StepOutcome
    {
        $shell = $this->shell($context);

        if ($shell->succeeds($this->check($context))) {
            return StepOutcome::AlreadySatisfied;
        }

        $shell->run($this->packages($context));

        $shell->install(self::HARDENING, self::DROP_IN);
        $context->put('sshd_dropin_created', true);
        $shell->run($this->reloadSsh());

        if (! $this->executor->probe($context->vmHost())->reachable) {
            $shell->run($this->removeDropIn());
            $context->put('sshd_dropin_created', false);

            throw ProvisioningException::lockedOut('A fresh SSH session failed after the sshd hardening; the drop-in was removed again.');
        }

        $context->log('second SSH session works with the hardened sshd');

        return StepOutcome::Applied;
    }

    public function verify(ProvisioningContext $context): VerifyResult
    {
        $shell = $this->shell($context);
        $effective = $shell->output(ProvisioningScript::make('sshd-effective', "sudo sshd -T | grep -Ei '^(passwordauthentication|permitrootlogin|kbdinteractiveauthentication) ' | sort"));

        if ($effective !== "kbdinteractiveauthentication no\npasswordauthentication no\npermitrootlogin no") {
            return VerifyResult::failed('sshd does not report password, keyboard-interactive and root login as disabled.');
        }

        $client = $this->clients->for($context->hypervisor());

        for ($i = 0; $i < 12; $i++) {
            if ($client->agentPing($context->node(), (int) $context->vmid())) {
                return VerifyResult::ok();
            }

            $context->sleep(5);
        }

        return VerifyResult::failed('The QEMU guest agent does not answer through the hypervisor.');
    }

    public function rollback(ProvisioningContext $context): void
    {
        if ($context->flag('sshd_dropin_created')) {
            $this->shell($context)->run($this->removeDropIn());
            $context->put('sshd_dropin_created', false);
        }
    }

    private function shell(ProvisioningContext $context): VmShell
    {
        return new VmShell($this->executor, $context, $context->vmHost());
    }

    private function check(ProvisioningContext $context): ProvisioningScript
    {
        return ProvisioningScript::make('base-check', <<<'SH'
dpkg -s qemu-guest-agent chrony unattended-upgrades ufw curl ca-certificates jq dnsutils >/dev/null 2>&1
[ "$(timedatectl show -p Timezone --value)" = {{TZ}} ]
systemctl is-active --quiet qemu-guest-agent
grep -qx 'PasswordAuthentication no' /etc/ssh/sshd_config.d/10-hardening.conf
grep -qx 'KbdInteractiveAuthentication no' /etc/ssh/sshd_config.d/10-hardening.conf
grep -qx 'PermitRootLogin no' /etc/ssh/sshd_config.d/10-hardening.conf
SH, ['TZ' => $context->config->timezone]);
    }

    private function packages(ProvisioningContext $context): ProvisioningScript
    {
        return ProvisioningScript::make('base-packages', <<<'SH'
export DEBIAN_FRONTEND=noninteractive
sudo apt-get update -qq
sudo -E apt-get -y -qq upgrade
sudo -E apt-get -y -qq install qemu-guest-agent unattended-upgrades ufw curl ca-certificates jq htop chrony dnsutils
printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' | sudo tee /etc/apt/apt.conf.d/20auto-upgrades >/dev/null
sudo systemctl start qemu-guest-agent
sudo timedatectl set-timezone {{TZ}}
SH, ['TZ' => $context->config->timezone]);
    }

    private function reloadSsh(): ProvisioningScript
    {
        return ProvisioningScript::make('sshd-reload', 'sudo sshd -t && sudo systemctl reload ssh');
    }

    private function removeDropIn(): ProvisioningScript
    {
        return ProvisioningScript::make('sshd-dropin-remove', 'sudo rm -f {{DROPIN}} && sudo sshd -t && sudo systemctl reload ssh', ['DROPIN' => self::DROP_IN]);
    }
}
