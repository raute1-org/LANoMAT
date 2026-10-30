<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Steps;

use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Provisioning\Contracts\ProvisioningStep;
use App\Modules\Provisioning\Domain\PlannedAction;
use App\Modules\Provisioning\Domain\StepDescription;
use App\Modules\Provisioning\Domain\VerifyResult;
use App\Modules\Provisioning\Enums\StepOutcome;
use App\Modules\Provisioning\Support\ProvisioningContext;
use App\Modules\Provisioning\Support\ProvisioningScript;
use App\Modules\Provisioning\Support\VmShell;

/**
 * Step 2 of the runbook: Docker Engine from the official apt repository,
 * `daemon.json` written BEFORE the first start (json-file log rotation). The
 * login user is not added to the docker group (root-equivalent): everything
 * runs through sudo.
 */
class InstallDocker implements ProvisioningStep
{
    public function __construct(private readonly RemoteExecutor $executor) {}

    public function key(): string
    {
        return 'install_docker';
    }

    public function describe(ProvisioningContext $context): StepDescription
    {
        return new StepDescription(
            __('provisioning.steps.install_docker.title'),
            [PlannedAction::ssh($this->check()->render()), PlannedAction::ssh($this->install()->render())],
            [__('provisioning.steps.install_docker.verify')],
            __('provisioning.steps.install_docker.rollback'),
        );
    }

    public function apply(ProvisioningContext $context): StepOutcome
    {
        $shell = $this->shell($context);

        if ($shell->succeeds($this->check())) {
            return StepOutcome::AlreadySatisfied;
        }

        $shell->run($this->install());

        return StepOutcome::Applied;
    }

    public function verify(ProvisioningContext $context): VerifyResult
    {
        $driver = $this->shell($context)->output(ProvisioningScript::make('docker-info', "sudo docker info --format '{{.LoggingDriver}}'"));

        return $driver === 'json-file' ? VerifyResult::ok() : VerifyResult::failed('Docker does not use the json-file log driver.');
    }

    public function rollback(ProvisioningContext $context): void
    {
        // Intentionally empty: removing a container engine is not a safe automatic rollback.
    }

    private function shell(ProvisioningContext $context): VmShell
    {
        return new VmShell($this->executor, $context, $context->vmHost());
    }

    private function check(): ProvisioningScript
    {
        return ProvisioningScript::make('docker-check', <<<'SH'
command -v docker >/dev/null
sudo systemctl is-active --quiet docker
sudo docker compose version >/dev/null
SH);
    }

    private function install(): ProvisioningScript
    {
        return ProvisioningScript::make('docker-install', <<<'SH'
export DEBIAN_FRONTEND=noninteractive
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
sudo tee /etc/apt/sources.list.d/docker.sources >/dev/null <<EOF
Types: deb
URIs: https://download.docker.com/linux/debian
Suites: $(. /etc/os-release && echo "$VERSION_CODENAME")
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
sudo apt-get update -qq
sudo mkdir -p /etc/docker
sudo tee /etc/docker/daemon.json >/dev/null <<'EOF'
{"log-driver":"json-file","log-opts":{"max-size":"10m","max-file":"3"}}
EOF
sudo -E apt-get -y -qq install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo docker run --rm hello-world >/dev/null
SH);
    }
}
