<?php

use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Hosts\SshRemoteExecutor;
use App\Modules\Hosts\Testing\FakeRemoteExecutor;
use App\Modules\Provisioning\Exceptions\ProvisioningException;
use App\Modules\Provisioning\Support\CrossCheckHostKeyTrust;
use App\Modules\Provisioning\Testing\FakeHostKeyScanner;

const KEY_A = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGtleWtleWtleWtleWtleWtleWtleWtleWtleWtleWtleWtl';
const KEY_B = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG90aGVyb3RoZXJvdGhlcm90aGVyb3RoZXJvdGhlcm90aGVy';

function trustWith(?string $direct, string $keyscanOutput): array
{
    $executor = new FakeRemoteExecutor;
    $executor->queueResult('ssh-keyscan', new CommandResult(0, $keyscanOutput, ''));

    return [new CrossCheckHostKeyTrust(new FakeHostKeyScanner($direct), $executor), $executor];
}

it('returns the fingerprint when both independent paths agree', function () {
    $fp = SshRemoteExecutor::deriveFingerprint(KEY_A);
    [$trust, $executor] = trustWith($fp, "# 203.0.113.10:22 SSH-2.0-OpenSSH\n203.0.113.10 ".KEY_A."\n203.0.113.10 ".KEY_B."\n");

    expect($trust->establish('203.0.113.10', 22, RemoteHost::factory()->make(['id' => 5])))->toBe($fp);
    expect($executor->commands[0]['command'])->toContain('ssh-keyscan')->toContain("'203.0.113.10'");
});

it('rejects a differing view, a silent VM, an empty independent path and a missing jump host', function () {
    $fp = SshRemoteExecutor::deriveFingerprint(KEY_A);
    $jump = RemoteHost::factory()->make(['id' => 5]);

    [$trust] = trustWith($fp, '203.0.113.10 '.KEY_B."\n");
    expect(fn () => $trust->establish('203.0.113.10', 22, $jump))->toThrow(ProvisioningException::class, 'man-in-the-middle');

    [$trust] = trustWith(null, '203.0.113.10 '.KEY_A."\n");
    expect(fn () => $trust->establish('203.0.113.10', 22, $jump))->toThrow(ProvisioningException::class, 'does not present');

    [$trust] = trustWith($fp, "garbage\n");
    expect(fn () => $trust->establish('203.0.113.10', 22, $jump))->toThrow(ProvisioningException::class, 'did not return');

    [$trust] = trustWith($fp, '203.0.113.10 '.KEY_A."\n");
    expect(fn () => $trust->establish('203.0.113.10', 22, null))->toThrow(ProvisioningException::class, 'independent path');
});
