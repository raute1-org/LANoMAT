<?php

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Models\User;
use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Provisioning\Actions\ExecuteProvisioningRun;
use App\Modules\Provisioning\Actions\StartProvisioningRun;
use App\Modules\Provisioning\Domain\VmSummary;
use App\Modules\Provisioning\Enums\RunStatus;
use App\Modules\Provisioning\Enums\StepStatus;
use App\Modules\Provisioning\Enums\VmPowerState;
use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Jobs\RunProvisioningPlanJob;
use App\Modules\Provisioning\Models\ProvisioningRun;
use App\Modules\Provisioning\Plans\TestVmWithLancache;
use App\Modules\Provisioning\Support\RunOptions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

function statuses(ProvisioningRun $run): array
{
    return $run->steps->pluck('status.value', 'step')->all();
}

it('stops at the first failure, leaves later steps pending and undoes only the failed step by default', function () {
    $w = provisioningWorld();
    $w['hv']->failOn('startVm');

    $run = runPlan($w);

    expect($run->status)->toBe(RunStatus::Failed)->and($run->error)->toContain('fake failure in startVm');
    expect(statuses($run))->toMatchArray([
        'download_image' => 'verified', 'create_vm' => 'verified', 'start_vm' => 'rolled_back',
        'wait_for_ssh' => 'pending', 'base_setup' => 'pending', 'egress_isolation' => 'pending',
    ]);
    // The earlier step's work stays: the VM and its host record still exist.
    expect($w['hv']->vms)->toHaveCount(1)->and(RemoteHost::query()->where('name', 'lancache-test')->exists())->toBeTrue();
    expect($run->steps->firstWhere('step', 'start_vm')->error)->toContain('startVm');
});

it('also rolls back the earlier applied steps only with the explicit rollback option', function () {
    $w = provisioningWorld();
    $w['hv']->failOn('startVm');

    $run = runPlan($w, options: new RunOptions(rollbackAll: true));

    expect($run->status)->toBe(RunStatus::RolledBack);
    expect($w['hv']->vms)->toBe([])->and(RemoteHost::query()->where('name', 'lancache-test')->exists())->toBeFalse();
    expect(statuses($run)['create_vm'])->toBe('rolled_back');
    $w['hv']->assertCalled('deleteVm');
});

it('can switch the automatic rollback of the failed step off', function () {
    $w = provisioningWorld();
    $w['hv']->failOn('startVm');

    $run = runPlan($w, options: new RunOptions(rollbackFailedStep: false));

    expect(statuses($run)['start_vm'])->toBe('failed');
});

it('never touches a VM id that belongs to another machine', function () {
    $w = provisioningWorld();
    $w['hv']->vms[100] = new VmSummary(100, 'someone-elses-vm', VmPowerState::Running, 2, 1, 1);

    $run = runPlan($w, ['vmid' => 100]);

    expect($run->status)->toBe(RunStatus::Failed)->and($run->error)->toContain('refusing to touch it');
    $w['hv']->assertNotCalled('createVm');
    $w['hv']->assertNotCalled('deleteVm');
});

it('refuses an image storage without the import content type', function () {
    $w = provisioningWorld();

    $run = runPlan($w, ['image_storage' => 'local-lvm']);

    expect($run->status)->toBe(RunStatus::Failed)->and($run->error)->toContain('does not accept cloud images');
    $w['hv']->assertNotCalled('downloadImage');
});

it('keeps the safety net armed when the fresh SSH session fails after loading the isolation', function () {
    $w = provisioningWorld();
    $w['remote']->probeFailsAfter = 'sudo nft -f /etc/nftables.conf';

    $run = runPlan($w);

    expect($run->status)->toBe(RunStatus::Failed)->and($run->error)->toContain('safety net stays armed');
    expect($w['remote']->index('systemd-run --unit=isolation-safety-net'))->toBeInt();
    // Never cancelled, never persisted as enabled.
    expect($w['remote']->index('enable nftables'))->toBeFalse();
    // The failed step is rolled back (table removed) as a best effort; the timer is the real net.
    expect($w['remote']->index('nft delete table inet lanomat_isolation 2>/dev/null'))->toBeInt();
    expect(statuses($run)['egress_isolation'])->toBe('rolled_back');
});

it('removes the sshd hardening again when a fresh session cannot log in afterwards', function () {
    $w = provisioningWorld();
    $w['remote']->probeFailsAfter = 'systemctl reload ssh';

    $run = runPlan($w);

    expect($run->status)->toBe(RunStatus::Failed)->and($run->error)->toContain('drop-in was removed');
    expect($w['remote']->index('sudo rm -f '))->toBeInt();
    expect(statuses($run)['install_docker'])->toBe('pending');
});

it('refuses to enable ufw when the automation would be locked out', function () {
    $w = provisioningWorld();
    $w['remote']->rules['SSH_CLIENT'] = fn () => new CommandResult(0, "192.0.2.77\n", '');

    $run = runPlan($w);

    expect($run->status)->toBe(RunStatus::Failed)->and($run->error)->toContain('lock the automation out');
    expect($w['remote']->index('ufw --force enable'))->toBeFalse()->and($w['remote']->index('ufw default deny'))->toBeFalse();
});

it('does not pin or log in when the two host key views differ', function () {
    $w = provisioningWorld();
    $w['scanner']->answers = ['SHA256:'.str_repeat('A', 43)];

    $run = runPlan($w);

    expect($run->status)->toBe(RunStatus::Failed)->and($run->error)->toContain('possible man-in-the-middle');
    expect(RemoteHost::query()->where('name', 'lancache-test')->value('host_fingerprint'))->toBeNull();
    expect(statuses($run)['base_setup'])->toBe('pending');
});

it('has no trust without an independent path to the hypervisor host', function () {
    $w = provisioningWorld(jumpHost: false);

    $run = runPlan($w);

    expect($run->status)->toBe(RunStatus::Failed)->and($run->error)->toContain('independent path');
    expect(RemoteHost::query()->where('name', 'lancache-test')->value('host_fingerprint'))->toBeNull();
});

it('refuses a second active run for the same target and a held lock', function () {
    $w = provisioningWorld();
    $start = fn () => app(StartProvisioningRun::class)->handle(TestVmWithLancache::KEY, sampleConfig(), $w['hypervisor'], $w['actor'], dispatch: false);

    $first = $start();
    expect($start)->toThrow(ProvisioningException::class, 'already active');

    $lock = Cache::lock('provisioning:target:'.$first->target_key, 60);
    $lock->get();
    $run = app(ExecuteProvisioningRun::class)->handle($first, fn (int $s) => null);
    $lock->release();

    expect($run->status)->toBe(RunStatus::Failed)->and($run->error)->toContain('already active');
    expect($w['hv']->calls)->toBe([]);
    // A finished run frees the target.
    expect($start())->toBeInstanceOf(ProvisioningRun::class);
});

it('queues the run as a never-retried job with a generous timeout', function () {
    Queue::fake();
    $w = provisioningWorld();

    $run = app(StartProvisioningRun::class)->handle(TestVmWithLancache::KEY, sampleConfig(), $w['hypervisor'], $w['actor']);

    Queue::assertPushed(RunProvisioningPlanJob::class, fn ($job) => $job->runId === $run->id && $job->tries === 1 && $job->timeout >= 9 * 600);
    expect($run->status)->toBe(RunStatus::Pending)->and($run->steps)->toHaveCount(9);
});

it('runs through the queue job end to end', function () {
    $w = provisioningWorld();
    $run = app(StartProvisioningRun::class)->handle(TestVmWithLancache::KEY, sampleConfig(), $w['hypervisor'], $w['actor'], dispatch: false);

    (new RunProvisioningPlanJob($run->id))->handle(app(ExecuteProvisioningRun::class));

    expect($run->fresh()->status)->toBe(RunStatus::Succeeded);
});

it('is orga/admin only and re-authorizes the stored actor inside the job', function () {
    $w = provisioningWorld();

    expect(fn () => app(StartProvisioningRun::class)->handle(TestVmWithLancache::KEY, sampleConfig(), $w['hypervisor'], User::factory()->create(), dispatch: false))
        ->toThrow(AuthorizationException::class);

    $run = app(StartProvisioningRun::class)->handle(TestVmWithLancache::KEY, sampleConfig(), $w['hypervisor'], $w['actor'], dispatch: false);
    $w['actor']->forceFill(['role' => Role::Participant])->save();

    $executed = app(ExecuteProvisioningRun::class)->handle($run->fresh(), fn (int $s) => null);

    expect($executed->status)->toBe(RunStatus::Failed)->and($w['hv']->calls)->toBe([]);
    expect($executed->steps->every(fn ($s) => $s->status === StepStatus::Pending))->toBeTrue();
});

it('rejects an unknown plan and an invalid config before anything is stored', function () {
    $w = provisioningWorld();

    expect(fn () => app(StartProvisioningRun::class)->handle('nope', sampleConfig(), $w['hypervisor'], $w['actor'], dispatch: false))->toThrow(ProvisioningException::class, 'Unknown');
    expect(fn () => app(StartProvisioningRun::class)->handle(TestVmWithLancache::KEY, sampleConfig(['ip' => '999.1.1.1']), $w['hypervisor'], $w['actor'], dispatch: false))->toThrow(ProvisioningException::class, 'ip');
    expect(DB::table('provisioning_runs')->count())->toBe(0);
});
