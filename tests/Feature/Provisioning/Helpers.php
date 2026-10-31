<?php

use App\Models\User;
use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Domain\HostProbe;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Hosts\SshRemoteExecutor;
use App\Modules\Hosts\Testing\FakeRemoteExecutor;
use App\Modules\Provisioning\Actions\ExecuteProvisioningRun;
use App\Modules\Provisioning\Actions\StartProvisioningRun;
use App\Modules\Provisioning\Contracts\HostKeyScanner;
use App\Modules\Provisioning\Models\Hypervisor;
use App\Modules\Provisioning\Models\ProvisioningRun;
use App\Modules\Provisioning\Plans\TestVmWithLancache;
use App\Modules\Provisioning\Support\RunOptions;
use App\Modules\Provisioning\Testing\FakeHostKeyScanner;
use App\Modules\Provisioning\Testing\FakeHypervisorClient;

/** A remote that answers like a VM and can be scripted per command substring. */
class ScriptedRemote extends FakeRemoteExecutor
{
    /** @var array<string, callable(): CommandResult> */
    public array $rules = [];

    public ?string $probeFailsAfter = null;

    private bool $tripped = false;

    public bool $composeUploaded = false;

    public function run(RemoteHost $host, string $command): CommandResult
    {
        $this->commands[] = ['host_id' => $host->id, 'command' => $command];

        if ($this->probeFailsAfter !== null && str_contains($command, $this->probeFailsAfter)) {
            $this->tripped = true;
        }

        foreach ($this->rules as $match => $rule) {
            if (str_contains($command, $match)) {
                return $rule();
            }
        }

        // Pre-apply "is it already satisfied?" checks answer no until the stack was uploaded.
        if (str_contains($command, 'sha256sum <') && str_contains($command, '/opt/lancache/.env')) {
            return new CommandResult($this->composeUploaded ? 0 : 1, '', '');
        }

        return new CommandResult(0, '', '');
    }

    public function upload(RemoteHost $host, string $contents, string $remotePath): void
    {
        parent::upload($host, $contents, $remotePath);

        if (str_contains($contents, 'lancachenet/monolithic')) {
            $this->composeUploaded = true;
        }
    }

    public function probe(RemoteHost $host): HostProbe
    {
        return $this->tripped ? new HostProbe(false, null, 'unreachable') : new HostProbe(true, null, null);
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_column($this->commands, 'command');
    }

    public function index(string $needle): int|false
    {
        foreach ($this->all() as $i => $command) {
            if (str_contains($command, $needle)) {
                return $i;
            }
        }

        return false;
    }
}

const TEST_ED25519_BODY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGtleWtleWtleWtleWtleWtleWtleWtleWtleWtleWtleWtl';

function sampleConfig(array $override = []): array
{
    return array_replace_recursive([
        'name' => 'lancache-test',
        'memory_mb' => 8192,
        'cores' => 4,
        'disk_gb' => 200,
        'image' => [
            'url' => 'https://cloud.example.test/debian-13-genericcloud-amd64.qcow2',
            'checksum' => str_repeat('ab', 64),
            'algorithm' => 'sha512',
            'filename' => 'debian-13-genericcloud-amd64.qcow2',
        ],
        'admin_ssh_keys' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGFkbWlua2V5YWRtaW5rZXlhZG1pbmtleWFkbWlua2V5 admin@example.test'],
        'ip' => '203.0.113.10',
        'prefix' => 24,
        'gateway' => '203.0.113.1',
        'search_domain' => 'example.test',
        'timezone' => 'Europe/Berlin',
        'allowed_source_cidrs' => ['203.0.113.0/24', '198.51.100.0/24'],
        'egress_allowed_cidrs' => ['198.51.100.0/24'],
        'negative_probe_host' => '203.0.113.99',
    ], $override);
}

/** The fingerprint both host-key paths agree on. */
function agreedFingerprint(): string
{
    return SshRemoteExecutor::deriveFingerprint(TEST_ED25519_BODY);
}

/**
 * @return array{hv: FakeHypervisorClient, remote: ScriptedRemote, scanner: FakeHostKeyScanner, hypervisor: Hypervisor, actor: User}
 */
function provisioningWorld(bool $jumpHost = true): array
{
    $hv = fakeHypervisor();
    $remote = new ScriptedRemote;
    app()->instance(RemoteExecutor::class, $remote);

    $scanner = new FakeHostKeyScanner(agreedFingerprint());
    app()->instance(HostKeyScanner::class, $scanner);

    $jump = $jumpHost ? RemoteHost::factory()->create(['name' => 'pve-host']) : null;
    $remote->rules['ssh-keyscan'] = fn () => new CommandResult(0, '203.0.113.10 '.TEST_ED25519_BODY."\n", '');
    $remote->rules['SSH_CLIENT'] = fn () => new CommandResult(0, "203.0.113.5\n", '');
    $remote->rules['sshd -T'] = fn () => new CommandResult(0, "kbdinteractiveauthentication no\npasswordauthentication no\npermitrootlogin no\n", '');
    $remote->rules['docker info'] = fn () => new CommandResult(0, "json-file\n", '');
    $remote->rules['lancache-heartbeat'] = fn () => new CommandResult(0, '204', '');
    // "Already satisfied?" probes answer no on a fresh VM.
    $remote->rules['dpkg -s qemu-guest-agent'] = fn () => new CommandResult(1, '', '');
    $remote->rules['command -v docker >/dev/null'] = fn () => new CommandResult(1, '', '');
    $remote->rules['<<< LANoMAT docker-user <<<$/p'] = fn () => new CommandResult(1, '', '');
    $remote->rules['sha256sum < /etc/nftables.d'] = fn () => new CommandResult(1, '', '');

    return [
        'hv' => $hv,
        'remote' => $remote,
        'scanner' => $scanner,
        'hypervisor' => Hypervisor::factory()->create(['node' => 'node1', 'jump_host_id' => $jump?->id]),
        'actor' => User::factory()->orga()->create(),
    ];
}

function runPlan(array $world, array $config = [], ?RunOptions $options = null): ProvisioningRun
{
    $run = app(StartProvisioningRun::class)->handle(
        TestVmWithLancache::KEY,
        sampleConfig($config),
        $world['hypervisor'],
        $world['actor'],
        $options ?? new RunOptions,
        dispatch: false,
    );

    return app(ExecuteProvisioningRun::class)->handle($run, fn (int $s) => null);
}
