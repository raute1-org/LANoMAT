<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

use App\Modules\Lancache\Domain\LancacheConfig;
use App\Modules\Provisioning\Exceptions\HypervisorException;
use App\Modules\Provisioning\Exceptions\ProvisioningException;
use InvalidArgumentException;

/**
 * All inputs of the `test-vm-lancache` plan (docs/infra/proxmox-test-vm-lancache.md,
 * "Inputs"). Contains no secrets: SSH public keys only. The VM's own SSH key
 * pair is generated per run and never part of the config.
 */
final readonly class TestVmConfig
{
    /** @var list<string> */
    public array $adminSshKeys;

    /** @var list<string> */
    public array $allowedSourceCidrs;

    /** @var list<string> */
    public array $egressAllowedCidrs;

    /** @var list<string> */
    public array $upstreamDns;

    /**
     * @param  list<string>  $adminSshKeys
     * @param  list<string>  $allowedSourceCidrs
     * @param  list<string>  $egressAllowedCidrs
     * @param  list<string>  $upstreamDns
     */
    public function __construct(
        public string $name,
        public ?string $node,
        public ?int $vmid,
        public int $memoryMb,
        public int $cores,
        public int $diskGb,
        public string $storage,
        public string $imageStorage,
        public string $bridge,
        public ImageSource $image,
        array $adminSshKeys,
        public string $ciUser,
        public string $ip,
        public int $prefix,
        public string $gateway,
        public string $nameserver,
        public ?string $searchDomain,
        public string $timezone,
        public string $wanIf,
        array $allowedSourceCidrs,
        array $egressAllowedCidrs,
        public string $cacheRoot,
        public string $cacheDiskSize,
        public string $minFreeDisk,
        public string $cacheIndexSize,
        public string $cacheMaxAge,
        array $upstreamDns,
        public string $dnsImage,
        public string $monolithicImage,
        public ?string $negativeProbeHost,
        public int $safetyNetMinutes,
    ) {
        $this->adminSshKeys = $adminSshKeys;
        $this->allowedSourceCidrs = $allowedSourceCidrs;
        $this->egressAllowedCidrs = $egressAllowedCidrs;
        $this->upstreamDns = $upstreamDns;

        // The typed sub-objects validate their own fields; build them once now so a bad config fails at
        // plan time, not halfway through a run.
        $this->lancache();
        $this->firewall();
    }

    /**
     * @param  array<string, mixed>  $d  decoded JSON config (snake_case keys)
     */
    public static function fromArray(array $d): self
    {
        try {
            $image = is_array($d['image'] ?? null) ? $d['image'] : throw ProvisioningException::invalidConfig('image');
            $gateway = Validate::ip(Validate::string($d['gateway'] ?? null, 'gateway'), 'gateway');
            $keys = array_map(fn (mixed $k): string => Validate::publicKey(is_string($k) ? $k : '', 'admin_ssh_keys'), (array) ($d['admin_ssh_keys'] ?? []));

            if ($keys === []) {
                throw ProvisioningException::invalidConfig('admin_ssh_keys');
            }

            $checksum = Validate::string($image['checksum'] ?? null, 'image.checksum');
            $filename = Validate::string($image['filename'] ?? null, 'image.filename');

            return new self(
                name: Validate::name(Validate::string($d['name'] ?? null, 'name'), 'name'),
                node: isset($d['node']) ? Validate::token(Validate::string($d['node'], 'node'), 'node') : null,
                vmid: isset($d['vmid']) ? Validate::int($d['vmid'], 'vmid', 100, 999_999_999) : null,
                memoryMb: Validate::int($d['memory_mb'] ?? 8192, 'memory_mb', 512, 1_048_576),
                cores: Validate::int($d['cores'] ?? 4, 'cores', 1, 256),
                diskGb: Validate::int($d['disk_gb'] ?? 200, 'disk_gb', 8, 65_536),
                storage: Validate::token((string) ($d['storage'] ?? 'local-lvm'), 'storage'),
                imageStorage: Validate::token((string) ($d['image_storage'] ?? 'local'), 'image_storage'),
                bridge: Validate::token((string) ($d['bridge'] ?? 'vmbr0'), 'bridge'),
                // The stored file name carries the checksum prefix: an existing file of that name is by construction the verified one.
                image: new ImageSource(
                    Validate::string($image['url'] ?? null, 'image.url'),
                    $checksum,
                    (string) ($image['algorithm'] ?? 'sha512'),
                    substr(strtolower($checksum), 0, 12).'-'.$filename,
                ),
                adminSshKeys: array_values($keys),
                ciUser: Validate::token((string) ($d['ci_user'] ?? 'debian'), 'ci_user'),
                ip: Validate::ip(Validate::string($d['ip'] ?? null, 'ip'), 'ip'),
                prefix: Validate::int($d['prefix'] ?? null, 'prefix', 8, 30),
                gateway: $gateway,
                nameserver: Validate::ip((string) ($d['nameserver'] ?? $gateway), 'nameserver'),
                searchDomain: isset($d['search_domain']) ? Validate::domain(Validate::string($d['search_domain'], 'search_domain'), 'search_domain') : null,
                timezone: Validate::timezone((string) ($d['timezone'] ?? 'Etc/UTC'), 'timezone'),
                wanIf: Validate::token((string) ($d['wan_if'] ?? 'eth0'), 'wan_if'),
                allowedSourceCidrs: array_values(array_map(fn (mixed $c): string => Validate::cidr(is_string($c) ? $c : '', 'allowed_source_cidrs'), (array) ($d['allowed_source_cidrs'] ?? []))),
                egressAllowedCidrs: array_values(array_map(fn (mixed $c): string => Validate::cidr(is_string($c) ? $c : '', 'egress_allowed_cidrs'), (array) ($d['egress_allowed_cidrs'] ?? []))),
                cacheRoot: (string) ($d['cache_root'] ?? '/srv/lancache'),
                cacheDiskSize: (string) ($d['cache_disk_size'] ?? '150g'),
                minFreeDisk: (string) ($d['min_free_disk'] ?? '15g'),
                cacheIndexSize: (string) ($d['cache_index_size'] ?? '500m'),
                cacheMaxAge: (string) ($d['cache_max_age'] ?? '3650d'),
                upstreamDns: array_values(array_map(fn (mixed $i): string => Validate::ip(is_string($i) ? $i : '', 'upstream_dns'), (array) ($d['upstream_dns'] ?? [$gateway]))),
                dnsImage: (string) ($d['lancache_dns_image'] ?? 'lancachenet/lancache-dns:latest'),
                monolithicImage: (string) ($d['lancache_monolithic_image'] ?? 'lancachenet/monolithic:latest'),
                negativeProbeHost: isset($d['negative_probe_host']) ? Validate::ip(Validate::string($d['negative_probe_host'], 'negative_probe_host'), 'negative_probe_host') : null,
                safetyNetMinutes: Validate::int($d['safety_net_minutes'] ?? (int) config('provisioning.safety_net_minutes', 6), 'safety_net_minutes', 2, 60),
            );
        } catch (InvalidArgumentException $e) {
            throw ProvisioningException::invalidConfig($e->getMessage());
        } catch (HypervisorException $e) {
            throw ProvisioningException::invalidConfig($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'node' => $this->node,
            'vmid' => $this->vmid,
            'memory_mb' => $this->memoryMb,
            'cores' => $this->cores,
            'disk_gb' => $this->diskGb,
            'storage' => $this->storage,
            'image_storage' => $this->imageStorage,
            'bridge' => $this->bridge,
            // Stored with the original file name, so a round trip re-applies the checksum prefix exactly once.
            'image' => ['url' => $this->image->url, 'checksum' => $this->image->checksum, 'algorithm' => $this->image->algorithm, 'filename' => substr($this->image->filename, 13)],
            'admin_ssh_keys' => $this->adminSshKeys,
            'ci_user' => $this->ciUser,
            'ip' => $this->ip,
            'prefix' => $this->prefix,
            'gateway' => $this->gateway,
            'nameserver' => $this->nameserver,
            'search_domain' => $this->searchDomain,
            'timezone' => $this->timezone,
            'wan_if' => $this->wanIf,
            'allowed_source_cidrs' => $this->allowedSourceCidrs,
            'egress_allowed_cidrs' => $this->egressAllowedCidrs,
            'cache_root' => $this->cacheRoot,
            'cache_disk_size' => $this->cacheDiskSize,
            'min_free_disk' => $this->minFreeDisk,
            'cache_index_size' => $this->cacheIndexSize,
            'cache_max_age' => $this->cacheMaxAge,
            'upstream_dns' => $this->upstreamDns,
            'lancache_dns_image' => $this->dnsImage,
            'lancache_monolithic_image' => $this->monolithicImage,
            'negative_probe_host' => $this->negativeProbeHost,
            'safety_net_minutes' => $this->safetyNetMinutes,
        ];
    }

    public function lancache(): LancacheConfig
    {
        return new LancacheConfig(
            ip: $this->ip,
            upstreamDns: $this->upstreamDns,
            cacheRoot: $this->cacheRoot,
            cacheDiskSize: $this->cacheDiskSize,
            minFreeDisk: $this->minFreeDisk,
            cacheIndexSize: $this->cacheIndexSize,
            cacheMaxAge: $this->cacheMaxAge,
            timezone: $this->timezone,
            dnsImage: $this->dnsImage,
            monolithicImage: $this->monolithicImage,
        );
    }

    public function firewall(): FirewallPolicy
    {
        return new FirewallPolicy($this->wanIf, $this->gateway, $this->allowedSourceCidrs, $this->egressAllowedCidrs);
    }
}
