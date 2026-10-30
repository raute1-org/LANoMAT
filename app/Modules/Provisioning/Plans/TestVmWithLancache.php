<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Plans;

use App\Modules\Provisioning\Steps\ApplyEgressIsolation;
use App\Modules\Provisioning\Steps\ApplyIngressFirewall;
use App\Modules\Provisioning\Steps\ApplyLancache;
use App\Modules\Provisioning\Steps\BaseSetup;
use App\Modules\Provisioning\Steps\CreateVm;
use App\Modules\Provisioning\Steps\DownloadVerifiedImage;
use App\Modules\Provisioning\Steps\InstallDocker;
use App\Modules\Provisioning\Steps\StartVm;
use App\Modules\Provisioning\Steps\WaitForSsh;
use App\Modules\Provisioning\Support\ProvisioningPlan;

/**
 * Plan `test-vm-lancache`: the automated form of
 * docs/infra/proxmox-test-vm-lancache.md. A hardened Debian 13 VM on Proxmox
 * with Docker, a LanCache and egress isolation. The isolation step is last on
 * purpose. WireGuard and Pelican are separate, later plans.
 */
class TestVmWithLancache
{
    public const string KEY = 'test-vm-lancache';

    public function plan(): ProvisioningPlan
    {
        return new ProvisioningPlan(self::KEY, 'Test-VM mit Docker, LanCache und Egress-Isolation', [
            app(DownloadVerifiedImage::class),
            app(CreateVm::class),
            app(StartVm::class),
            app(WaitForSsh::class),
            app(BaseSetup::class),
            app(InstallDocker::class),
            app(ApplyIngressFirewall::class),
            app(ApplyLancache::class),
            app(ApplyEgressIsolation::class),
        ]);
    }
}
