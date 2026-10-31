<?php

declare(strict_types=1);

namespace App\Modules\Provisioning;

use App\Modules\Provisioning\Contracts\HypervisorClient;
use App\Modules\Provisioning\Domain\CreateVmSpec;
use App\Modules\Provisioning\Domain\HypervisorNode;
use App\Modules\Provisioning\Domain\HypervisorStorage;
use App\Modules\Provisioning\Domain\ImageSource;
use App\Modules\Provisioning\Domain\VmSummary;
use App\Modules\Provisioning\Enums\VmPowerState;
use App\Modules\Provisioning\Exceptions\HypervisorException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Proxmox VE REST client (`https://<host>:8006/api2/json`), endpoints and
 * parameters checked against the official API viewer
 * (https://pve.proxmox.com/pve-docs/api-viewer/).
 *
 * Auth: API token header `Authorization: PVEAPIToken=user@realm!tokenid=secret`.
 * The secret is only ever put in that header, never in a URL, message or log.
 *
 * TLS: verification is on. A self-signed Proxmox certificate is handled by an
 * SPKI pin (`sha256//base64`) enforced by curl on the same connection
 * (CURLOPT_PINNEDPUBLICKEY); verification is only relaxed when a pin is set,
 * never otherwise.
 *
 * Shapes learned from a real node (pvesh output): booleans arrive as 0/1,
 * `serial`/`tags` exist only on some VMs, `tags` is `;`-separated, `mem` can
 * exceed `maxmem`, `nextid` may be a string. Every mutating endpoint returns a
 * UPID; this client waits for the task before returning.
 */
class ProxmoxApiClient implements HypervisorClient
{
    /** @var Closure(int): void */
    private Closure $sleeper;

    /**
     * @param  (Closure(int): void)|null  $sleeper  seconds; injectable so tests do not sleep
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $tokenId,
        private readonly string $tokenSecret,
        private readonly ?string $tlsPin = null,
        private readonly int $timeoutSeconds = 20,
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $taskTimeoutSeconds = 900,
        private readonly int $taskPollSeconds = 3,
        ?Closure $sleeper = null,
    ) {
        if (! str_starts_with($baseUrl, 'https://')) {
            throw new InvalidArgumentException('The hypervisor base URL must use https.');
        }

        if ($tlsPin !== null && preg_match('/^sha256\/\/[A-Za-z0-9+\/]{43}=(;sha256\/\/[A-Za-z0-9+\/]{43}=)*$/', $tlsPin) !== 1) {
            throw new InvalidArgumentException('The TLS pin must look like sha256//<base64>.');
        }

        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Guzzle options for the TLS policy; public so the policy is unit-tested
     * (a real TLS handshake is not part of the test suite).
     *
     * @return array<string, mixed>
     */
    public function tlsOptions(): array
    {
        if ($this->tlsPin === null) {
            return ['verify' => true];
        }

        return ['verify' => false, 'curl' => [CURLOPT_PINNEDPUBLICKEY => $this->tlsPin]];
    }

    public function version(): string
    {
        return (string) ($this->get('/version')['version'] ?? throw HypervisorException::malformed('version'));
    }

    public function listNodes(): array
    {
        return array_map(fn (array $n): HypervisorNode => new HypervisorNode(
            name: (string) ($n['node'] ?? ''),
            online: ($n['status'] ?? '') === 'online',
            maxCpu: (int) ($n['maxcpu'] ?? 0),
            memTotal: (int) ($n['maxmem'] ?? 0),
            memUsed: (int) ($n['mem'] ?? 0),
            sslFingerprint: isset($n['ssl_fingerprint']) ? (string) $n['ssl_fingerprint'] : null,
            uptime: (int) ($n['uptime'] ?? 0),
        ), $this->rows($this->get('/nodes')));
    }

    public function listStorages(string $node): array
    {
        return array_map(fn (array $s): HypervisorStorage => new HypervisorStorage(
            name: (string) ($s['storage'] ?? ''),
            type: (string) ($s['type'] ?? ''),
            content: array_values(array_filter(explode(',', (string) ($s['content'] ?? '')))),
            active: (bool) ($s['active'] ?? false),
            enabled: (bool) ($s['enabled'] ?? true),
            shared: (bool) ($s['shared'] ?? false),
            total: (int) ($s['total'] ?? 0),
            avail: (int) ($s['avail'] ?? 0),
            used: (int) ($s['used'] ?? 0),
        ), $this->rows($this->get($this->path($node, 'storage'))));
    }

    public function listVms(string $node): array
    {
        return array_map(fn (array $v): VmSummary => $this->toSummary($v), $this->rows($this->get($this->path($node, 'qemu'))));
    }

    public function getVm(string $node, int $vmid): ?VmSummary
    {
        try {
            return $this->toSummary($this->get($this->path($node, "qemu/{$vmid}/status/current")) + ['vmid' => $vmid]);
        } catch (HypervisorException $e) {
            // A missing VM is a 500 "Configuration file ... does not exist".
            if ($e->translationKey === 'provisioning.errors.hypervisor_not_found') {
                return null;
            }

            throw $e;
        }
    }

    public function nextFreeVmid(): int
    {
        $data = $this->send('GET', '/cluster/nextid');

        if (! is_int($data) && ! (is_string($data) && ctype_digit($data))) {
            throw HypervisorException::malformed('nextid');
        }

        return (int) $data;
    }

    public function createVm(CreateVmSpec $spec): void
    {
        $this->assertVmidFree($spec->vmid);

        $c = $spec->cloudInit;
        $body = [
            'vmid' => $spec->vmid,
            'name' => $spec->name,
            'memory' => $spec->memoryMb,
            'balloon' => $spec->balloonMb,
            'cores' => $spec->cores,
            'cpu' => $spec->cpu,
            'ostype' => 'l26',
            'machine' => $spec->machine,
            'bios' => $spec->bios,
            'net0' => "virtio,bridge={$spec->bridge}",
            'scsihw' => $spec->scsihw,
            'agent' => 'enabled='.($spec->agent ? '1' : '0'),
            'onboot' => $spec->onboot ? 1 : 0,
            'scsi0' => "{$spec->storage}:0,import-from={$spec->importFromVolume},discard=on,iothread=1,ssd=1",
            'boot' => 'order=scsi0',
            'ide2' => "{$spec->storage}:cloudinit",
            'ciuser' => $c->user,
            // `sshkeys` has format "urlencoded": the VALUE is percent-encoded once
            // here, the form encoder then encodes the body again as usual.
            'sshkeys' => rawurlencode(implode("\n", $c->sshPublicKeys)),
            'ipconfig0' => "ip={$c->ip}/{$c->prefix},gw={$c->gateway}",
            'nameserver' => $c->nameserver,
            'tags' => implode(';', $spec->tags),
        ];

        if ($c->searchDomain !== null && $c->searchDomain !== '') {
            $body['searchdomain'] = $c->searchDomain;
        }

        if ($spec->bios === 'ovmf') {
            $body['efidisk0'] = "{$spec->storage}:1,efitype=4m,pre-enrolled-keys=0";
        }

        if ($spec->serial) {
            $body['serial0'] = 'socket';
            $body['vga'] = 'serial0';
        }

        $this->waitFor($spec->node, $this->send('POST', $this->path($spec->node, 'qemu'), $body));

        $this->resizeDisk($spec->node, $spec->vmid, 'scsi0', $spec->diskSizeGb);
    }

    public function importDisk(string $node, int $vmid, string $disk, string $storage, string $volume): void
    {
        $this->attachDisk($node, $vmid, $disk, "{$storage}:0,import-from={$volume},discard=on,iothread=1,ssd=1");
    }

    public function attachDisk(string $node, int $vmid, string $disk, string $value): void
    {
        $this->waitFor($node, $this->send('POST', $this->path($node, "qemu/{$vmid}/config"), [$disk => $value]));
    }

    public function resizeDisk(string $node, int $vmid, string $disk, int $sizeGb): void
    {
        $this->waitFor($node, $this->send('PUT', $this->path($node, "qemu/{$vmid}/resize"), ['disk' => $disk, 'size' => "{$sizeGb}G"]));
    }

    public function startVm(string $node, int $vmid): void
    {
        $this->waitFor($node, $this->send('POST', $this->path($node, "qemu/{$vmid}/status/start")));
    }

    public function stopVm(string $node, int $vmid): void
    {
        $this->waitFor($node, $this->send('POST', $this->path($node, "qemu/{$vmid}/status/stop")));
    }

    public function deleteVm(string $node, int $vmid, bool $purge = true): void
    {
        $query = $purge ? ['purge' => 1, 'destroy-unreferenced-disks' => 1] : [];

        $this->waitFor($node, $this->send('DELETE', $this->path($node, "qemu/{$vmid}"), $query));
    }

    public function vmStatus(string $node, int $vmid): VmPowerState
    {
        return VmPowerState::fromProxmox($this->get($this->path($node, "qemu/{$vmid}/status/current"))['status'] ?? null);
    }

    public function agentPing(string $node, int $vmid): bool
    {
        try {
            $this->send('POST', $this->path($node, "qemu/{$vmid}/agent/ping"));

            return true;
        } catch (HypervisorException $e) {
            // "QEMU guest agent is not running" / "VM is not running" arrive as HTTP 500.
            if ($e->httpStatus === 500) {
                return false;
            }

            throw $e;
        }
    }

    public function hasVolume(string $node, string $storage, string $volume): bool
    {
        $rows = $this->rows($this->send('GET', $this->path($node, 'storage/'.rawurlencode($storage).'/content'), ['content' => 'import']));

        foreach ($rows as $row) {
            if (($row['volid'] ?? null) === $volume) {
                return true;
            }
        }

        return false;
    }

    public function downloadImage(string $node, string $storage, ImageSource $image): string
    {
        $upid = $this->send('POST', $this->path($node, 'storage/'.rawurlencode($storage).'/download-url'), [
            'url' => $image->url,
            'content' => 'import',
            'filename' => $image->filename,
            'checksum' => $image->checksum,
            'checksum-algorithm' => $image->algorithm,
        ]);

        $this->waitFor($node, $upid);

        return "{$storage}:import/{$image->filename}";
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toSummary(array $row): VmSummary
    {
        return new VmSummary(
            vmid: (int) ($row['vmid'] ?? 0),
            name: (string) ($row['name'] ?? ''),
            status: VmPowerState::fromProxmox(isset($row['status']) ? (string) $row['status'] : null),
            cpus: (int) ($row['cpus'] ?? 0),
            maxMem: (int) ($row['maxmem'] ?? 0),
            maxDisk: (int) ($row['maxdisk'] ?? 0),
            tags: array_values(array_filter(explode(';', (string) ($row['tags'] ?? '')))),
        );
    }

    private function assertVmidFree(int $vmid): void
    {
        try {
            $this->send('GET', '/cluster/nextid', ['vmid' => $vmid]);
        } catch (HypervisorException $e) {
            if (in_array($e->httpStatus, [400, 500], true)) {
                throw HypervisorException::vmidInUse($vmid);
            }

            throw $e;
        }
    }

    private function waitFor(string $node, mixed $upid): void
    {
        // Some config updates complete synchronously and return null.
        if (! is_string($upid) || ! str_starts_with($upid, 'UPID:')) {
            return;
        }

        $waited = 0;

        while (true) {
            $status = $this->get($this->path($node, 'tasks/'.rawurlencode($upid).'/status'));

            if (($status['status'] ?? '') === 'stopped') {
                $exit = (string) ($status['exitstatus'] ?? '');

                if ($exit === 'OK' || str_starts_with($exit, 'WARNINGS')) {
                    return;
                }

                throw HypervisorException::taskFailed($exit === '' ? 'no exit status' : $exit);
            }

            if ($waited >= $this->taskTimeoutSeconds) {
                throw HypervisorException::taskTimeout();
            }

            ($this->sleeper)($this->taskPollSeconds);
            $waited += $this->taskPollSeconds;
        }
    }

    private function path(string $node, string $rest): string
    {
        if (preg_match('/^[A-Za-z0-9-]+$/', $node) !== 1) {
            throw HypervisorException::malformed('node name');
        }

        return "/nodes/{$node}/{$rest}";
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $data = $this->send('GET', $path);

        return is_array($data) ? $data : throw HypervisorException::malformed($path);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $data): array
    {
        if (! is_array($data)) {
            throw HypervisorException::malformed('list');
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function send(string $method, string $path, array $params = []): mixed
    {
        try {
            $request = $this->pending();

            $response = match (true) {
                $method === 'GET' => $request->get($path, $params),
                $method === 'DELETE' => $request->delete($path.($params === [] ? '' : '?'.http_build_query($params))),
                $method === 'PUT' => $request->asForm()->put($path, $params),
                default => $request->asForm()->post($path, $params),
            };
        } catch (ConnectionException) {
            throw HypervisorException::unreachable();
        }

        return $this->decode($response, $path);
    }

    private function pending(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/api2/json')
            ->withHeaders(['Authorization' => "PVEAPIToken={$this->tokenId}={$this->tokenSecret}"])
            ->acceptJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->withOptions($this->tlsOptions());
    }

    private function decode(Response $response, string $path): mixed
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw HypervisorException::unauthorized($status);
        }

        if (! $response->successful()) {
            $message = $this->errorMessage($response);

            if ($status === 404 || ($status === 500 && str_contains($message, 'does not exist'))) {
                throw HypervisorException::notFound($path);
            }

            throw HypervisorException::api($status, $message);
        }

        $json = $response->json();

        if (! is_array($json) || ! array_key_exists('data', $json)) {
            throw HypervisorException::malformed($path);
        }

        return $json['data'];
    }

    private function errorMessage(Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            $parts = [];

            if (isset($json['message']) && is_string($json['message'])) {
                $parts[] = $json['message'];
            }

            if (isset($json['errors']) && is_array($json['errors'])) {
                foreach ($json['errors'] as $key => $message) {
                    $parts[] = (is_string($key) ? "{$key}: " : '').(is_scalar($message) ? (string) $message : '');
                }
            }

            if ($parts !== []) {
                return trim(implode('; ', $parts));
            }
        }

        return $response->reason();
    }
}
