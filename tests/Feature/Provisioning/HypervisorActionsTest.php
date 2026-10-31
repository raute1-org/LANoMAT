<?php

use App\Models\User;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Provisioning\Actions\ProbeHypervisor;
use App\Modules\Provisioning\Actions\RegisterHypervisor;
use App\Modules\Provisioning\Contracts\HypervisorClientFactory;
use App\Modules\Provisioning\Exceptions\HypervisorException;
use App\Modules\Provisioning\Models\Hypervisor;
use App\Modules\Provisioning\NullHypervisorClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function hvData(array $override = []): array
{
    return array_merge([
        'name' => 'lab', 'base_url' => 'https://pve.example.test:8006', 'node' => 'node1',
        'token_id' => 'lanomat@pve!provisioning', 'tls_pin' => null, 'jump_host_id' => null,
    ], $override);
}

it('registers a hypervisor with an encrypted, hidden, non-fillable token secret', function () {
    $hv = app(RegisterHypervisor::class)->handle(hvData(), 'super-secret-token', User::factory()->orga()->create());

    expect($hv->token_secret)->toBe('super-secret-token');
    expect(DB::table('hypervisors')->value('token_secret'))->not->toContain('super-secret-token');
    expect($hv->toArray())->not->toHaveKey('token_secret');
    expect(json_encode($hv))->not->toContain('super-secret-token');

    // Mass assignment must not be able to set the secret.
    $other = new Hypervisor(hvData(['name' => 'x']) + ['token_secret' => 'mass']);
    expect($other->token_secret ?? null)->toBeNull();
});

it('validates url, token id, pin, secret and jump host', function (array $override, string $secret, string $field) {
    $call = fn () => app(RegisterHypervisor::class)->handle(hvData($override), $secret, User::factory()->orga()->create());

    try {
        $call();
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
})->with([
    'http url' => [['base_url' => 'http://pve.example.test:8006'], 's', 'base_url'],
    'url with path' => [['base_url' => 'https://pve.example.test:8006/x'], 's', 'base_url'],
    'url with credentials' => [['base_url' => 'https://u:p@pve.example.test'], 's', 'base_url'],
    'bad token id' => [['token_id' => 'root'], 's', 'token_id'],
    'bad node' => [['node' => 'a b'], 's', 'node'],
    'bad pin' => [['tls_pin' => 'abc'], 's', 'tls_pin'],
    'empty secret' => [[], '', 'token_secret'],
    'secret with space' => [[], 'a b', 'token_secret'],
    'unknown jump host' => [['jump_host_id' => 999999], 's', 'jump_host_id'],
]);

it('accepts a valid pin and an existing jump host', function () {
    $jump = RemoteHost::factory()->create();
    $hv = app(RegisterHypervisor::class)->handle(hvData(['tls_pin' => 'sha256//'.str_repeat('A', 43).'=', 'jump_host_id' => $jump->id]), 's', User::factory()->orga()->create());

    expect($hv->jumpHost->is($jump))->toBeTrue();
});

it('is orga/admin only', function () {
    expect(fn () => app(RegisterHypervisor::class)->handle(hvData(), 's', User::factory()->create()))->toThrow(AuthorizationException::class);
    expect(fn () => app(RegisterHypervisor::class)->handle(hvData(), 's', User::factory()->helper()->create()))->toThrow(AuthorizationException::class);
    expect(app(RegisterHypervisor::class)->handle(hvData(), 's', User::factory()->admin()->create()))->toBeInstanceOf(Hypervisor::class);

    $hv = Hypervisor::factory()->create();
    expect(fn () => app(ProbeHypervisor::class)->handle($hv, User::factory()->create()))->toThrow(AuthorizationException::class);
});

it('probes read-only and summarizes version, free RAM, disks and import-capable storages', function () {
    $fake = fakeHypervisor();
    $hv = Hypervisor::factory()->create(['node' => 'node1']);

    $summary = app(ProbeHypervisor::class)->handle($hv, User::factory()->orga()->create());

    expect($summary->version)->toBe('9.0.0-fake')
        ->and($summary->memFreeBytes())->toBe(48_000_000_000)
        ->and(collect($summary->diskStorages())->pluck('name')->all())->toBe(['local-lvm'])
        ->and(collect($summary->importStorages())->pluck('name')->all())->toBe(['local'])
        ->and($summary->freeDiskBytes())->toBe(700_000_000_000);
    expect($hv->fresh()->last_version)->toBe('9.0.0-fake')->and($hv->fresh()->last_probed_at)->not->toBeNull();

    foreach (array_column($fake->calls, 'method') as $method) {
        expect($method)->toBeIn(['version', 'listNodes', 'listStorages']);
    }
});

it('hands out the Null client when the credential row is incomplete', function () {
    config(['provisioning.driver' => 'proxmox']);
    $hv = Hypervisor::factory()->make(['token_id' => '']);

    expect(app(HypervisorClientFactory::class)->for($hv))->toBeInstanceOf(NullHypervisorClient::class);
    expect(fn () => app(HypervisorClientFactory::class)->for($hv)->version())->toThrow(HypervisorException::class);
});
