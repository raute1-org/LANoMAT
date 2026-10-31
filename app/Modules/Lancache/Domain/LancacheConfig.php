<?php

declare(strict_types=1);

namespace App\Modules\Lancache\Domain;

use InvalidArgumentException;

/**
 * Typed LanCache stack configuration (docs/infra/proxmox-test-vm-lancache.md,
 * step 3), replacing loose `services.lancache.*` values for the compose-based
 * setup. Pure: renders the `.env` and `docker-compose.yml` text. Every value is
 * validated against a strict pattern, so the rendered files cannot be used for
 * injection and need no quoting.
 *
 * Ports are bound to the cache IP only (never 0.0.0.0): Docker-published ports
 * bypass ufw, so the DOCKER-USER rules and the bind address are the two layers.
 */
final readonly class LancacheConfig
{
    public const string STACK_DIR = '/opt/lancache';

    /** @var list<string> */
    public array $upstreamDns;

    /**
     * @param  list<string>  $upstreamDns
     */
    public function __construct(
        public string $ip,
        array $upstreamDns,
        public string $cacheRoot = '/srv/lancache',
        public string $cacheDiskSize = '150g',
        public string $minFreeDisk = '15g',
        public string $cacheIndexSize = '500m',
        public string $cacheMaxAge = '3650d',
        public string $timezone = 'Etc/UTC',
        public string $dnsImage = 'lancachenet/lancache-dns:latest',
        public string $monolithicImage = 'lancachenet/monolithic:latest',
    ) {
        $this->assert(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false, 'ip');
        $this->assert($upstreamDns !== [], 'upstream_dns');

        foreach ($upstreamDns as $dns) {
            $this->assert(filter_var($dns, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false, 'upstream_dns');
        }

        $this->assert(preg_match('#^/[A-Za-z0-9/_.-]*$#', $cacheRoot) === 1 && ! str_contains($cacheRoot, '..') && $cacheRoot !== '/', 'cache_root');
        $this->assert(preg_match('/^\d+[gt]$/', $cacheDiskSize) === 1, 'cache_disk_size');
        $this->assert(preg_match('/^\d+[gt]$/', $minFreeDisk) === 1, 'min_free_disk');
        $this->assert(preg_match('/^\d+[mg]$/', $cacheIndexSize) === 1, 'cache_index_size');
        $this->assert(preg_match('/^\d+d$/', $cacheMaxAge) === 1, 'cache_max_age');
        $this->assert(preg_match('#^[A-Za-z]+(/[A-Za-z0-9_+-]+){0,2}$#', $timezone) === 1, 'timezone');

        foreach ([$dnsImage, $monolithicImage] as $image) {
            $this->assert(preg_match('/^[a-z0-9][a-z0-9._\/-]*(:[A-Za-z0-9._-]+)?(@sha256:[0-9a-f]{64})?$/', $image) === 1, 'image');
        }

        $this->upstreamDns = $upstreamDns;
    }

    public function envFile(): string
    {
        return implode("\n", [
            'USE_GENERIC_CACHE=true',
            "LANCACHE_IP={$this->ip}",
            "DNS_BIND_IP={$this->ip}",
            'UPSTREAM_DNS='.implode(' ', $this->upstreamDns),
            "CACHE_ROOT={$this->cacheRoot}",
            "CACHE_DISK_SIZE={$this->cacheDiskSize}",
            "MIN_FREE_DISK={$this->minFreeDisk}",
            "CACHE_INDEX_SIZE={$this->cacheIndexSize}",
            "CACHE_MAX_AGE={$this->cacheMaxAge}",
            "TZ={$this->timezone}",
        ])."\n";
    }

    public function composeFile(): string
    {
        return <<<YAML
services:
  dns:
    image: {$this->dnsImage}
    env_file: .env
    restart: unless-stopped
    ports:
      - \${DNS_BIND_IP}:53:53/udp
      - \${DNS_BIND_IP}:53:53/tcp
  monolithic:
    image: {$this->monolithicImage}
    env_file: .env
    restart: unless-stopped
    ports:
      - \${LANCACHE_IP}:80:80/tcp
      - \${LANCACHE_IP}:443:443/tcp
    volumes:
      - \${CACHE_ROOT}/cache:/data/cache
      - \${CACHE_ROOT}/logs:/data/logs

YAML;
    }

    private function assert(bool $ok, string $field): void
    {
        if (! $ok) {
            throw new InvalidArgumentException("Invalid LanCache setting: {$field}");
        }
    }
}
