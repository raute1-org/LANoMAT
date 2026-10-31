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
 * Step 5 of the runbook, always LAST: egress isolation inside the VM (the VM
 * may not initiate connections into private networks) with the lock-out safety
 * net. Sequence: write files, syntax check, ARM a systemd timer that deletes
 * the table again, apply, open a FRESH SSH session; only when that works the
 * timer is cancelled. When it does not, the timer is deliberately left armed.
 */
class ApplyEgressIsolation implements ProvisioningStep
{
    private const string TABLE_FILE = '/etc/nftables.d/10-isolation.nft';

    private const string CONF_FILE = '/etc/nftables.conf';

    public function __construct(private readonly RemoteExecutor $executor) {}

    public function key(): string
    {
        return 'egress_isolation';
    }

    public function describe(ProvisioningContext $context): StepDescription
    {
        $policy = $context->config->firewall();
        $minutes = $context->config->safetyNetMinutes;

        return new StepDescription(
            __('provisioning.steps.egress_isolation.title'),
            [
                PlannedAction::ssh($this->check($policy)->render()),
                PlannedAction::ssh($this->prepare()->render()),
                ...VmShell::installActions($policy->nftTable(), self::TABLE_FILE),
                ...VmShell::installActions($policy->nftConf(), self::CONF_FILE),
                PlannedAction::ssh($this->syntax()->render()),
                PlannedAction::ssh($this->arm($minutes)->render()),
                PlannedAction::ssh($this->load()->render()),
                PlannedAction::local(__('provisioning.steps.egress_isolation.second_session')),
                PlannedAction::ssh($this->finalize()->render()),
            ],
            [
                __('provisioning.steps.egress_isolation.verify_table'),
                __('provisioning.steps.egress_isolation.verify_positive'),
                __('provisioning.steps.egress_isolation.verify_negative'),
            ],
            __('provisioning.steps.egress_isolation.rollback'),
        );
    }

    public function apply(ProvisioningContext $context): StepOutcome
    {
        $policy = $context->config->firewall();
        $shell = $this->shell($context);

        if ($shell->succeeds($this->check($policy))) {
            return StepOutcome::AlreadySatisfied;
        }

        $shell->run($this->prepare());
        $shell->install($policy->nftTable(), self::TABLE_FILE);
        $shell->install($policy->nftConf(), self::CONF_FILE);
        $shell->run($this->syntax());

        $shell->run($this->arm($context->config->safetyNetMinutes));
        $context->log('safety net armed');
        $context->put('isolation_applied', true);

        $shell->run($this->load());

        if (! $this->executor->probe($context->vmHost())->reachable) {
            // The timer stays armed on purpose: it removes the table by itself.
            throw ProvisioningException::lockedOut("A fresh SSH session failed after loading the isolation; the safety net stays armed and removes the table in {$context->config->safetyNetMinutes} minutes.");
        }

        $context->log('second SSH session works, cancelling the safety net');
        $shell->run($this->finalize());

        return StepOutcome::Applied;
    }

    public function verify(ProvisioningContext $context): VerifyResult
    {
        $shell = $this->shell($context);
        $c = $context->config;

        $checks = [
            'the isolation table is missing' => ProvisioningScript::make('iso-table', 'sudo nft list table inet lanomat_isolation >/dev/null && sudo systemctl is-enabled --quiet nftables'),
            'DNS to the gateway does not answer' => ProvisioningScript::make('iso-dns', 'dig +time=3 +tries=1 @{{GATEWAY}} debian.org +short | grep -q .', ['GATEWAY' => $c->gateway]),
            'the gateway does not answer ping' => ProvisioningScript::make('iso-ping-gateway', 'ping -c1 -W3 {{GATEWAY}} >/dev/null', ['GATEWAY' => $c->gateway]),
            'HTTPS to the internet fails' => ProvisioningScript::make('iso-https', 'curl -sSI -m 10 https://deb.debian.org >/dev/null'),
        ];

        if ($c->negativeProbeHost !== null) {
            $checks += [
                'a private address is reachable from the VM' => ProvisioningScript::make('iso-private-curl', '! curl -m5 -sS -o /dev/null http://{{HOST}}', ['HOST' => $c->negativeProbeHost]),
                'a private address answers ping' => ProvisioningScript::make('iso-private-ping', '! ping -c1 -W3 {{HOST}} >/dev/null', ['HOST' => $c->negativeProbeHost]),
                'a private address is reachable from a container' => ProvisioningScript::make('iso-private-container', '! sudo docker run --rm curlimages/curl -m5 -sS -o /dev/null http://{{HOST}}', ['HOST' => $c->negativeProbeHost]),
                'a container cannot reach the internet' => ProvisioningScript::make('iso-container-https', 'sudo docker run --rm curlimages/curl -m8 -sSI https://debian.org >/dev/null'),
            ];
        }

        foreach ($checks as $failure => $script) {
            if (! $shell->succeeds($script)) {
                return VerifyResult::failed(ucfirst($failure).'.');
            }
        }

        return VerifyResult::ok();
    }

    public function rollback(ProvisioningContext $context): void
    {
        if ($context->flag('isolation_applied')) {
            $this->shell($context)->run($this->remove());
            $context->put('isolation_applied', false);
        }
    }

    private function shell(ProvisioningContext $context): VmShell
    {
        return new VmShell($this->executor, $context, $context->vmHost());
    }

    private function check(FirewallPolicy $policy): ProvisioningScript
    {
        return ProvisioningScript::make('iso-check', <<<'SH'
sudo nft list table inet lanomat_isolation >/dev/null
[ "$(sudo sha256sum < /etc/nftables.d/10-isolation.nft | cut -d' ' -f1)" = {{TABLE_HASH}} ]
[ "$(sudo sha256sum < /etc/nftables.conf | cut -d' ' -f1)" = {{CONF_HASH}} ]
sudo systemctl is-enabled --quiet nftables
SH, ['TABLE_HASH' => hash('sha256', $policy->nftTable()), 'CONF_HASH' => hash('sha256', $policy->nftConf())]);
    }

    private function prepare(): ProvisioningScript
    {
        return ProvisioningScript::make('iso-prepare', <<<'SH'
command -v nft >/dev/null || sudo -E DEBIAN_FRONTEND=noninteractive apt-get -y -qq install nftables
sudo mkdir -p /etc/nftables.d
[ ! -f /etc/nftables.conf ] || [ -f /root/nftables.conf.orig ] || sudo cp /etc/nftables.conf /root/nftables.conf.orig
SH);
    }

    private function syntax(): ProvisioningScript
    {
        return ProvisioningScript::make('iso-syntax', 'sudo nft -c -f /etc/nftables.conf');
    }

    private function arm(int $minutes): ProvisioningScript
    {
        return ProvisioningScript::make('iso-arm-safety-net', <<<'SH'
sudo systemctl stop isolation-safety-net.timer isolation-safety-net.service 2>/dev/null || true
sudo systemctl reset-failed isolation-safety-net.timer isolation-safety-net.service 2>/dev/null || true
sudo systemd-run --unit=isolation-safety-net --on-active={{MINUTES}} /usr/sbin/nft delete table inet lanomat_isolation
SH, ['MINUTES' => $minutes.'min']);
    }

    private function load(): ProvisioningScript
    {
        return ProvisioningScript::make('iso-load', 'sudo nft -f /etc/nftables.conf');
    }

    private function finalize(): ProvisioningScript
    {
        return ProvisioningScript::make('iso-finalize', <<<'SH'
sudo systemctl stop isolation-safety-net.timer
sudo systemctl enable nftables
sudo systemctl reload-or-restart nftables
SH);
    }

    private function remove(): ProvisioningScript
    {
        return ProvisioningScript::make('iso-remove', <<<'SH'
sudo nft delete table inet lanomat_isolation 2>/dev/null || true
sudo systemctl stop isolation-safety-net.timer 2>/dev/null || true
sudo systemctl disable nftables 2>/dev/null || true
sudo rm -f /etc/nftables.d/10-isolation.nft
[ ! -f /root/nftables.conf.orig ] || sudo cp /root/nftables.conf.orig /etc/nftables.conf
SH);
    }
}
