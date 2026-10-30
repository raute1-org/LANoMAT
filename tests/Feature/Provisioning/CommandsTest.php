<?php

require_once __DIR__.'/Helpers.php';

use App\Models\User;
use App\Modules\Provisioning\Enums\RunStatus;
use App\Modules\Provisioning\Models\Hypervisor;
use App\Modules\Provisioning\Models\ProvisioningRun;
use App\Support\Modules\Modules;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Output\BufferedOutput;

function configFile(array $override = []): string
{
    $path = tempnam(sys_get_temp_dir(), 'prov');
    file_put_contents($path, json_encode(sampleConfig($override)));

    return $path;
}

it('prints the dry run in German chrome with every step, and executes nothing', function () {
    $w = provisioningWorld();

    $this->artisan('provisioning:plan', ['--config' => configFile()])
        ->expectsOutputToContain('Trockenlauf: Es wird nichts ausgeführt')
        ->expectsOutputToContain('1. Download and verify the cloud image on the hypervisor [download_image]')
        ->expectsOutputToContain('[egress_isolation]')
        ->expectsOutputToContain('systemd-run --unit=isolation-safety-net --on-active=')
        ->expectsOutputToContain('define WAN_IF = "eth0"')
        ->assertSuccessful();

    expect($w['hv']->calls)->toBe([])->and($w['remote']->commands)->toBe([])->and(ProvisioningRun::query()->count())->toBe(0);
});

it('lists the steps in the documented order, isolation last', function () {
    provisioningWorld();
    $output = new BufferedOutput;
    Artisan::call('provisioning:plan', ['--config' => configFile()], $output);

    preg_match_all('/^\d+\. .* \[([a-z_]+)\]$/m', $output->fetch(), $m);

    expect($m[1])->toBe(['download_image', 'create_vm', 'start_vm', 'wait_for_ssh', 'base_setup', 'install_docker', 'ingress_firewall', 'lancache', 'egress_isolation']);
});

it('refuses to run without --confirm and creates nothing', function () {
    $w = provisioningWorld();

    $this->artisan('provisioning:run', ['--config' => configFile(), '--hypervisor' => $w['hypervisor']->name, '--as' => $w['actor']->id])
        ->expectsOutputToContain('Ohne ausdrückliche Bestätigung (--confirm) wird nichts ausgeführt.')
        ->assertFailed();

    expect(ProvisioningRun::query()->count())->toBe(0)->and($w['hv']->calls)->toBe([]);
});

it('runs synchronously with --confirm and reports the per-step result', function () {
    $w = provisioningWorld();

    $this->artisan('provisioning:run', ['--config' => configFile(), '--hypervisor' => $w['hypervisor']->name, '--as' => $w['actor']->id, '--confirm' => true, '--sync' => true])
        ->expectsOutputToContain('egress_isolation')
        ->expectsOutputToContain('erfolgreich abgeschlossen')
        ->assertSuccessful();

    expect(ProvisioningRun::query()->first()->status)->toBe(RunStatus::Succeeded);
});

it('queues without --sync and needs an authorized user', function () {
    Queue::fake();
    $w = provisioningWorld();

    $this->artisan('provisioning:run', ['--config' => configFile(), '--hypervisor' => $w['hypervisor']->name, '--as' => $w['actor']->id, '--confirm' => true])
        ->expectsOutputToContain('in die Queue gestellt')->assertSuccessful();

    $participant = User::factory()->create();
    DB::table('provisioning_runs')->delete();
    $this->artisan('provisioning:run', ['--config' => configFile(), '--hypervisor' => $w['hypervisor']->name, '--as' => $participant->id, '--confirm' => true])
        ->expectsOutputToContain('darf nicht provisionieren')->assertFailed();
});

it('probes a hypervisor read-only and prints the summary', function () {
    $w = provisioningWorld();

    $this->artisan('provisioning:probe-hypervisor', ['hypervisor' => $w['hypervisor']->name, '--as' => $w['actor']->id])
        ->expectsOutputToContain('Proxmox VE 9.0.0-fake')
        ->expectsOutputToContain('Storages für Cloud-Images (import): local')
        ->assertSuccessful();

    foreach (array_column($w['hv']->calls, 'method') as $method) {
        expect($method)->toBeIn(['version', 'listNodes', 'listStorages']);
    }
});

it('registers a hypervisor with the secret from the environment, never from an argument', function () {
    $actor = User::factory()->orga()->create();
    putenv('PROVISIONING_TOKEN_SECRET=from-env-1');

    try {
        $this->artisan('provisioning:register-hypervisor', ['name' => 'lab', 'base_url' => 'https://pve.example.test:8006', 'token_id' => 'lanomat@pve!provisioning', '--as' => $actor->id])
            ->expectsOutputToContain('registriert')->assertSuccessful();
    } finally {
        putenv('PROVISIONING_TOKEN_SECRET');
    }

    $hv = Hypervisor::query()->firstOrFail();
    expect($hv->token_secret)->toBe('from-env-1')
        ->and(DB::table('hypervisors')->value('token_secret'))->not->toContain('from-env-1');
});

it('reports a missing or unreadable config and an unknown hypervisor in German', function () {
    provisioningWorld();

    $this->artisan('provisioning:plan', ['--config' => '/nonexistent.json'])->expectsOutputToContain('Konfigurationsdatei')->assertFailed();
    $this->artisan('provisioning:plan', ['--config' => configFile(), '--hypervisor' => 'ghost'])->expectsOutputToContain('nicht registriert')->assertFailed();
    $this->artisan('provisioning:plan', ['--config' => configFile(['ip' => '300.1.1.1'])])->expectsOutputToContain('ungültig')->assertFailed();
});

it('is switched off with the module', function () {
    config(['modules.overrides.provisioning' => false]);
    app()->forgetInstance(Modules::class);
    app()->singleton(Modules::class, fn () => new Modules('full', ['provisioning' => false]));

    $this->artisan('provisioning:plan', ['--config' => configFile()])->expectsOutputToContain('Provisioning ist nicht aktiviert')->assertFailed();
});
