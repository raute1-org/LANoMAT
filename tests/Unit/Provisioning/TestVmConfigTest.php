<?php

require_once __DIR__.'/../../Feature/Provisioning/Helpers.php';

use App\Modules\Lancache\Domain\LancacheConfig;
use App\Modules\Provisioning\Domain\TestVmConfig;
use App\Modules\Provisioning\Exceptions\ProvisioningException;

it('round-trips through its array form without double-prefixing the image name', function () {
    $config = TestVmConfig::fromArray(sampleConfig());
    $again = TestVmConfig::fromArray($config->toArray());

    expect($again->toArray())->toBe($config->toArray())
        ->and($config->image->filename)->toBe('abababababab-debian-13-genericcloud-amd64.qcow2')
        ->and($config->upstreamDns)->toBe(['203.0.113.1'])
        ->and($config->nameserver)->toBe('203.0.113.1');
});

it('rejects hostile or malformed values', function (array $override) {
    expect(fn () => TestVmConfig::fromArray(sampleConfig($override)))->toThrow(ProvisioningException::class);
})->with([
    'bad name' => [['name' => 'Bad Name']],
    'shell in timezone' => [['timezone' => 'Europe/Berlin; id']],
    'shell in cache root' => [['cache_root' => '/srv/x; rm -rf /']],
    'root cache dir' => [['cache_root' => '/']],
    'traversal' => [['cache_root' => '/srv/../etc']],
    'bad cidr' => [['allowed_source_cidrs' => ['203.0.113.0/40']]],
    'bad image ref' => [['lancache_dns_image' => 'x; id']],
    'bad iface' => [['wan_if' => 'eth0 && id']],
    'bad key' => [['admin_ssh_keys' => ['not a key']]],
    'private key as admin key' => [['admin_ssh_keys' => ["-----BEGIN OPENSSH PRIVATE KEY-----\nabc"]]],
    'http image' => [['image' => ['url' => 'http://cloud.example.test/d.qcow2']]],
    'missing checksum' => [['image' => ['checksum' => '']]],
    'vmid too small' => [['vmid' => 5]],
    'safety net too short' => [['safety_net_minutes' => 0]],
]);

it('refuses a config without any allowed source subnet (SSH lock-out)', function () {
    expect(fn () => TestVmConfig::fromArray([...sampleConfig(), 'allowed_source_cidrs' => []]))->toThrow(ProvisioningException::class, 'subnet');
});

it('renders the LanCache files from the typed config, bound to the cache ip only', function () {
    $lancache = TestVmConfig::fromArray(sampleConfig(['cache_disk_size' => '300g']))->lancache();

    expect($lancache->envFile())->toBe("USE_GENERIC_CACHE=true\nLANCACHE_IP=203.0.113.10\nDNS_BIND_IP=203.0.113.10\nUPSTREAM_DNS=203.0.113.1\nCACHE_ROOT=/srv/lancache\nCACHE_DISK_SIZE=300g\nMIN_FREE_DISK=15g\nCACHE_INDEX_SIZE=500m\nCACHE_MAX_AGE=3650d\nTZ=Europe/Berlin\n");
    expect($lancache->composeFile())->toContain('- ${LANCACHE_IP}:80:80/tcp')->toContain('- ${DNS_BIND_IP}:53:53/udp')
        ->not->toContain('0.0.0.0')->toContain('restart: unless-stopped')->toContain('image: lancachenet/monolithic:latest');
    expect(fn () => new LancacheConfig('203.0.113.10', ['203.0.113.1'], cacheDiskSize: '150'))->toThrow(InvalidArgumentException::class);
});
