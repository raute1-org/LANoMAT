<?php

use App\Modules\Provisioning\Domain\CloudInitSpec;
use App\Modules\Provisioning\Domain\CreateVmSpec;
use App\Modules\Provisioning\Domain\ImageSource;
use App\Modules\Provisioning\Enums\VmPowerState;
use App\Modules\Provisioning\Exceptions\HypervisorException;
use App\Modules\Provisioning\ProxmoxApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const PVE = 'https://pve.example.test:8006/api2/json';
const UPID = 'UPID:node1:00001234:0000ABCD:67000000:qmcreate:9100:lanomat@pve!provisioning:';

function pveFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/Provisioning/proxmox/{$name}.json")), true);
}

function proxmox(?string $pin = null, int $taskTimeout = 9): ProxmoxApiClient
{
    return new ProxmoxApiClient(
        baseUrl: 'https://pve.example.test:8006',
        tokenId: 'lanomat@pve!provisioning',
        tokenSecret: 'secret-value-for-tests',
        tlsPin: $pin,
        taskTimeoutSeconds: $taskTimeout,
        taskPollSeconds: 3,
        sleeper: fn (int $s) => null,
    );
}

/** Http::fake is first-match-wins, so a second scenario in one test needs a fresh factory. */
function freshHttp(): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
}

function vmSpec(): CreateVmSpec
{
    return new CreateVmSpec(
        node: 'node1', vmid: 9100, name: 'lancache-test', memoryMb: 8192, balloonMb: 0, cores: 4, cpu: 'host',
        machine: 'q35', bios: 'ovmf', bridge: 'vmbr0', scsihw: 'virtio-scsi-single', agent: true, serial: true,
        onboot: true, storage: 'local-lvm', diskSizeGb: 200, importFromVolume: 'local:import/abc-debian.qcow2',
        cloudInit: new CloudInitSpec('debian', ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIexample admin@example.test', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIsecond ci@example.test'], '203.0.113.10', 24, '203.0.113.1', '203.0.113.1', 'example.test'),
    );
}

it('maps the real pvesh data shapes (REST envelope reconstructed) for nodes, storages, vms, version and nextid', function () {
    Http::fake([
        PVE.'/nodes' => Http::response(pveFixture('nodes')),
        PVE.'/nodes/node1/storage' => Http::response(pveFixture('storage')),
        PVE.'/nodes/node1/qemu' => Http::response(pveFixture('qemu')),
        PVE.'/version' => Http::response(pveFixture('version')),
        PVE.'/cluster/nextid' => Http::response(pveFixture('nextid')),
    ]);

    $client = proxmox();

    expect($client->version())->toBe('9.2.21');
    expect($client->nextFreeVmid())->toBe(9100);

    $nodes = $client->listNodes();
    expect($nodes)->toHaveCount(1)
        ->and($nodes[0]->name)->toBe('node1')
        ->and($nodes[0]->online)->toBeTrue()
        ->and($nodes[0]->memFree())->toBeGreaterThan(0)
        ->and($nodes[0]->sslFingerprint)->toStartWith('AB:AB');

    $storages = collect($client->listStorages('node1'))->keyBy('name');
    // Booleans are 0/1 ints on the wire; `content` is a comma-separated string.
    expect($storages['storage-thin-1']->active)->toBeTrue()
        ->and($storages['storage-thin-1']->acceptsContent('images'))->toBeTrue()
        ->and($storages['local']->acceptsContent('iso'))->toBeTrue()
        // The real node's file storage has no `import` content: image downloads must be refused there.
        ->and($storages['local']->acceptsContent('import'))->toBeFalse()
        ->and($storages['storage-pbs']->shared)->toBeTrue();

    $vms = $client->listVms('node1');
    expect($vms)->toHaveCount(10)
        ->and($vms[0]->status)->toBe(VmPowerState::Running);

    // `tags` only exists on some VMs and is ';' separated.
    $tagged = collect($vms)->first(fn ($v) => $v->tags !== []);
    expect($tagged->tags)->toBe(['tag-a', 'tag-b']);
    // Running VMs report disk 0 and a VM may use more memory than maxmem: nothing may choke on that.
    expect(collect($vms)->every(fn ($v) => $v->maxMem > 0))->toBeTrue();
});

it('sends the API token header and never puts the secret in a URL', function () {
    Http::fake([PVE.'/version' => Http::response(pveFixture('version'))]);

    proxmox()->version();

    Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['PVEAPIToken=lanomat@pve!provisioning=secret-value-for-tests']
        && ! str_contains($r->url(), 'secret-value'));
});

it('creates a VM: free-id check, urlencoded sshkeys, import-from, task wait, resize', function () {
    Http::fake(function (Request $r) {
        return match (true) {
            $r->method() === 'GET' && str_contains($r->url(), '/cluster/nextid') => Http::response(['data' => '9100']),
            $r->method() === 'POST' && str_ends_with($r->url(), '/nodes/node1/qemu') => Http::response(['data' => UPID]),
            $r->method() === 'PUT' && str_ends_with($r->url(), '/qemu/9100/resize') => Http::response(['data' => UPID]),
            str_contains($r->url(), '/tasks/') => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
            default => Http::response(['message' => 'unexpected'], 500),
        };
    });

    proxmox()->createVm(vmSpec());

    Http::assertSent(function (Request $r) {
        if ($r->method() !== 'POST' || ! str_ends_with($r->url(), '/nodes/node1/qemu')) {
            return false;
        }

        parse_str($r->body(), $body);

        return $body['scsi0'] === 'local-lvm:0,import-from=local:import/abc-debian.qcow2,discard=on,iothread=1,ssd=1'
            && $body['ide2'] === 'local-lvm:cloudinit'
            && $body['efidisk0'] === 'local-lvm:1,efitype=4m,pre-enrolled-keys=0'
            && $body['ipconfig0'] === 'ip=203.0.113.10/24,gw=203.0.113.1'
            && $body['agent'] === 'enabled=1'
            && $body['serial0'] === 'socket' && $body['vga'] === 'serial0'
            && $body['balloon'] === '0' && $body['boot'] === 'order=scsi0'
            // The API wants the value percent-encoded once on top of the form encoding.
            && $body['sshkeys'] === rawurlencode("ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIexample admin@example.test\nssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIsecond ci@example.test")
            && ! str_contains($r->body(), 'secret-value');
    });

    Http::assertSent(function (Request $r) {
        parse_str($r->body(), $body);

        return $r->method() === 'PUT' && ($body['disk'] ?? null) === 'scsi0' && ($body['size'] ?? null) === '200G';
    });
});

it('refuses to create a VM whose id is already in use', function () {
    Http::fake([PVE.'/cluster/nextid*' => Http::response(['data' => null, 'message' => "VM 9100 already exists\n"], 500)]);

    expect(fn () => proxmox()->createVm(vmSpec()))->toThrow(HypervisorException::class);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('fails with the task exit status and times out on tasks that never stop', function () {
    Http::fake([PVE.'/nodes/node1/qemu/9100/status/start' => Http::response(['data' => UPID]),
        PVE.'/nodes/node1/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'start failed: no space']])]);

    expect(fn () => proxmox()->startVm('node1', 9100))->toThrow(HypervisorException::class, 'start failed');

    freshHttp();
    Http::fake([PVE.'/nodes/node1/qemu/9100/status/start' => Http::response(['data' => UPID]),
        PVE.'/nodes/node1/tasks/*' => Http::response(['data' => ['status' => 'running']])]);

    expect(fn () => proxmox(taskTimeout: 6)->startVm('node1', 9100))->toThrow(HypervisorException::class, 'did not finish');
});

it('maps an agent that is not running to false and other errors to exceptions', function () {
    Http::fake([PVE.'/nodes/node1/qemu/9100/agent/ping' => Http::response(['data' => null, 'message' => 'QEMU guest agent is not running'], 500)]);
    expect(proxmox()->agentPing('node1', 9100))->toBeFalse();

    freshHttp();
    Http::fake([PVE.'/nodes/node1/qemu/9100/agent/ping' => Http::response(['data' => ['result' => []]])]);
    expect(proxmox()->agentPing('node1', 9100))->toBeTrue();

    freshHttp();
    Http::fake([PVE.'/nodes/node1/qemu/9100/agent/ping' => Http::response([], 403)]);
    expect(fn () => proxmox()->agentPing('node1', 9100))->toThrow(HypervisorException::class);
});

it('maps unknown VMs to null and 401 to a typed error', function () {
    Http::fake([PVE.'/nodes/node1/qemu/9999/status/current' => Http::response(['data' => null, 'message' => 'Configuration file \'nodes/node1/qemu-server/9999.conf\' does not exist'], 500)]);
    expect(proxmox()->getVm('node1', 9999))->toBeNull();

    freshHttp();
    Http::fake([PVE.'/version' => Http::response('no ticket', 401)]);
    expect(fn () => proxmox()->version())->toThrow(HypervisorException::class, 'rejected');
});

it('deletes with purge and destroys unreferenced disks, stops via status/stop', function () {
    Http::fake([
        PVE.'/nodes/node1/qemu/9100*' => Http::response(['data' => UPID]),
        PVE.'/nodes/node1/qemu/9100/status/stop' => Http::response(['data' => UPID]),
        PVE.'/nodes/node1/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
    ]);

    proxmox()->stopVm('node1', 9100);
    proxmox()->deleteVm('node1', 9100);

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'purge=1') && str_contains($r->url(), 'destroy-unreferenced-disks=1'));
});

it('downloads an image with a mandatory, verified checksum onto an import storage', function () {
    Http::fake([
        PVE.'/nodes/node1/storage/local/download-url' => Http::response(['data' => UPID]),
        PVE.'/nodes/node1/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
        PVE.'/nodes/node1/storage/local/content*' => Http::response(['data' => [['volid' => 'local:import/abc-debian.qcow2', 'content' => 'import']]]),
    ]);

    $image = new ImageSource('https://cloud.example.test/debian.qcow2', str_repeat('AB', 64), 'sha512', 'abc-debian.qcow2');
    $volume = proxmox()->downloadImage('node1', 'local', $image);

    expect($volume)->toBe('local:import/abc-debian.qcow2')
        ->and(proxmox()->hasVolume('node1', 'local', $volume))->toBeTrue()
        ->and(proxmox()->hasVolume('node1', 'local', 'local:import/other.qcow2'))->toBeFalse();

    Http::assertSent(function (Request $r) {
        parse_str($r->body(), $b);

        return str_ends_with($r->url(), '/download-url') && $b['content'] === 'import'
            && $b['checksum-algorithm'] === 'sha512' && $b['checksum'] === str_repeat('ab', 64);
    });
});

it('rejects image sources without a valid checksum or with an unsafe url', function (string $url, string $sum, string $algo, string $file) {
    expect(fn () => new ImageSource($url, $sum, $algo, $file))->toThrow(HypervisorException::class);
})->with([
    'empty checksum' => ['https://cloud.example.test/d.qcow2', '', 'sha512', 'd.qcow2'],
    'wrong length' => ['https://cloud.example.test/d.qcow2', 'abcd', 'sha256', 'd.qcow2'],
    'md5 not allowed' => ['https://cloud.example.test/d.qcow2', str_repeat('a', 32), 'md5', 'd.qcow2'],
    'plain http' => ['http://cloud.example.test/d.qcow2', str_repeat('a', 64), 'sha256', 'd.qcow2'],
    'path traversal filename' => ['https://cloud.example.test/d.qcow2', str_repeat('a', 64), 'sha256', '../d.qcow2'],
]);

it('keeps TLS verification on and only relaxes it together with an enforced pin', function () {
    expect(proxmox()->tlsOptions())->toBe(['verify' => true]);

    $pin = 'sha256//'.str_repeat('A', 43).'=';
    expect(proxmox($pin)->tlsOptions())->toBe(['verify' => false, 'curl' => [CURLOPT_PINNEDPUBLICKEY => $pin]]);

    expect(fn () => new ProxmoxApiClient('http://pve.example.test', 'a@pve!t', 's'))->toThrow(InvalidArgumentException::class);
    expect(fn () => proxmox('not-a-pin'))->toThrow(InvalidArgumentException::class);
});

it('turns a connection failure into an unreachable error without leaking the token', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7 secret-value-for-tests'));

    try {
        proxmox()->version();
        $this->fail('expected exception');
    } catch (HypervisorException $e) {
        expect($e->getMessage())->not->toContain('secret-value')->and($e->translationKey)->toBe('provisioning.errors.hypervisor_unreachable');
    }
});
