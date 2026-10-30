<?php

require_once __DIR__.'/Helpers.php';

use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Provisioning\Actions\RunProvisioningPlan;
use App\Modules\Provisioning\Domain\TestVmConfig;
use App\Modules\Provisioning\Enums\RunStatus;
use App\Modules\Provisioning\Enums\StepStatus;
use App\Modules\Provisioning\Events\ProvisioningRunFinished;
use App\Modules\Provisioning\Events\ProvisioningStepChanged;
use App\Modules\Provisioning\Support\ProvisioningContext;
use App\Modules\Provisioning\Support\ProvisioningPlans;
use Illuminate\Support\Facades\Event;

it('runs the whole plan: every step verified, isolation last, safety net cancelled only after the fresh session', function () {
    Event::fake([ProvisioningStepChanged::class, ProvisioningRunFinished::class]);
    $w = provisioningWorld();

    $run = runPlan($w);

    expect($run->status)->toBe(RunStatus::Succeeded)->and($run->error)->toBeNull();
    expect($run->steps->pluck('step')->all())->toBe(['download_image', 'create_vm', 'start_vm', 'wait_for_ssh', 'base_setup', 'install_docker', 'ingress_firewall', 'lancache', 'egress_isolation']);
    expect($run->steps->every(fn ($s) => $s->status === StepStatus::Verified && ! $s->skipped))->toBeTrue();

    // VM: cloud-init carries the admin key AND the generated key; static ip; started.
    $spec = array_values($w['hv']->specs)[0];
    expect($spec->name)->toBe('lancache-test')
        ->and($spec->cloudInit->sshPublicKeys)->toHaveCount(2)
        ->and($spec->cloudInit->sshPublicKeys[1])->toStartWith('ssh-ed25519 ')
        ->and($spec->cloudInit->ip)->toBe('203.0.113.10')
        ->and($spec->importFromVolume)->toBe('local:import/abababababab-debian-13-genericcloud-amd64.qcow2')
        ->and($spec->balloonMb)->toBe(0)
        ->and($w['hv']->vms[100]->status->value)->toBe('running');

    // Host record: pinned out of band, encrypted key, lancache role.
    $host = RemoteHost::query()->where('name', 'lancache-test')->firstOrFail();
    expect($host->host_fingerprint)->toBe(agreedFingerprint())->and($host->role->value)->toBe('lancache');
    expect(DB::table('remote_hosts')->where('id', $host->id)->value('ssh_private_key'))->not->toContain('PRIVATE KEY');

    // Isolation is the last remote work; the safety net is armed before the load and cancelled after it.
    $remote = $w['remote'];
    $arm = $remote->index('systemd-run --unit=isolation-safety-net');
    $load = $remote->index('sudo nft -f /etc/nftables.conf');
    $cancel = $remote->index('systemctl stop isolation-safety-net.timer'."\n".'sudo systemctl enable nftables');
    expect($arm)->toBeInt()->and($load)->toBeGreaterThan($arm)->and($cancel)->toBeGreaterThan($load);
    expect($remote->index('docker compose up -d'))->toBeLessThan($arm);

    // No secret ever reaches a command line, an upload, a log or the stored context.
    $private = DB::table('remote_hosts')->where('id', $host->id)->exists() ? $host->ssh_private_key : '';
    expect($private)->toContain('PRIVATE KEY');
    foreach ($remote->all() as $command) {
        expect($command)->not->toContain('PRIVATE KEY')->not->toContain('secret');
    }
    foreach ($remote->uploads as $upload) {
        expect($upload['contents'])->not->toContain('PRIVATE KEY');
    }
    $stored = json_encode([DB::table('provisioning_run_steps')->get(), DB::table('provisioning_runs')->get()]);
    expect($stored)->not->toContain('PRIVATE KEY')->not->toContain('OPENSSH');
    expect(DB::table('provisioning_runs')->value('context'))->not->toContain('203.0.113.10');

    Event::assertDispatched(ProvisioningRunFinished::class, fn ($e) => $e->status === 'succeeded');
    Event::assertDispatched(ProvisioningStepChanged::class, fn ($e) => $e->step === 'egress_isolation' && $e->status === 'verified');
});

it('renders and uploads exactly the policy files', function () {
    $w = provisioningWorld();
    runPlan($w);

    $uploads = collect($w['remote']->uploads)->pluck('contents');
    $policy = TestVmConfig::fromArray(sampleConfig())->firewall();

    expect($uploads->contains($policy->nftTable()))->toBeTrue()
        ->and($uploads->contains($policy->nftConf()))->toBeTrue()
        ->and($uploads->contains($policy->dockerUserBlock()))->toBeTrue()
        ->and($uploads->contains(fn ($c) => str_contains($c, 'LANCACHE_IP=203.0.113.10') && str_contains($c, 'TZ=Europe/Berlin')))->toBeTrue();
});

it('executes every command the dry run describes (same renderer)', function () {
    $w = provisioningWorld();
    runPlan($w);

    $plan = app(ProvisioningPlans::class)->get('test-vm-lancache');
    $context = new ProvisioningContext(TestVmConfig::fromArray(sampleConfig()), $w['hypervisor'], null, dryRun: true);
    $executed = $w['remote']->all();
    $uploaded = collect($w['remote']->uploads)->pluck('contents', 'remote_path');

    foreach (app(RunProvisioningPlan::class)->describe($plan, $context) as $step) {
        foreach ($step->actions as $action) {
            if ($action->kind === 'ssh') {
                expect($executed)->toContain('bash -c '.escapeshellarg("set -euo pipefail\n".$action->text));
            }

            if ($action->kind === 'upload') {
                [$path, $contents] = explode("\n", $action->text, 2);
                expect($uploaded[$path] ?? null)->toBe($contents);
            }
        }
    }
});

it('does nothing on the hypervisor or the VM when only describing, and persists nothing', function () {
    $w = provisioningWorld();
    $plan = app(ProvisioningPlans::class)->get('test-vm-lancache');
    $context = new ProvisioningContext(TestVmConfig::fromArray(sampleConfig()), null, null, dryRun: true);

    $descriptions = app(RunProvisioningPlan::class)->describe($plan, $context);

    expect($descriptions)->toHaveCount(9);
    expect($w['hv']->calls)->toBe([])->and($w['remote']->commands)->toBe([])->and($w['remote']->uploads)->toBe([]);
    expect(DB::table('provisioning_runs')->count())->toBe(0);
});

it('is idempotent: a second run finds everything satisfied and changes nothing', function () {
    $w = provisioningWorld();
    expect(runPlan($w)->status)->toBe(RunStatus::Succeeded);

    $w['remote']->rules = array_filter($w['remote']->rules, fn ($_, $k) => ! in_array($k, ['dpkg -s qemu-guest-agent', 'command -v docker >/dev/null', '<<< LANoMAT docker-user <<<$/p', 'sha256sum < /etc/nftables.d'], true), ARRAY_FILTER_USE_BOTH);
    $w['remote']->composeUploaded = true;
    $uploadsBefore = count($w['remote']->uploads);
    $creates = $w['hv']->count('createVm');

    $second = runPlan($w);

    expect([$second->status->value, $second->error])->toBe(['succeeded', null]);
    expect($second->steps->every(fn ($s) => $s->skipped))->toBeTrue();
    expect($w['hv']->count('createVm'))->toBe($creates)->and(count($w['remote']->uploads))->toBe($uploadsBefore);
    expect(RemoteHost::query()->where('name', 'lancache-test')->count())->toBe(1);
});
