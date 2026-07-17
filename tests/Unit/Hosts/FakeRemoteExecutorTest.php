<?php

use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Domain\HostProbe;
use App\Modules\Hosts\Models\RemoteHost;
use PHPUnit\Framework\ExpectationFailedException;

it('records a run and returns the default CommandResult', function () {
    $fake = fakeRemote();
    $host = RemoteHost::factory()->create();

    $result = $fake->run($host, 'docker ps');

    expect($result)->toBeInstanceOf(CommandResult::class)
        ->and($result->ok())->toBeTrue()
        ->and($result->exitCode)->toBe(0)
        ->and($result->stdout)->toBe('')
        ->and($result->stderr)->toBe('');

    $fake->assertRan('docker ps');
});

it('returns a queued CommandResult matching a command substring', function () {
    $fake = fakeRemote();
    $host = RemoteHost::factory()->create();

    $fake->queueResult('docker ps', new CommandResult(1, '', 'permission denied'));

    $result = $fake->run($host, 'docker ps -a');

    expect($result->ok())->toBeFalse()
        ->and($result->exitCode)->toBe(1)
        ->and($result->stderr)->toBe('permission denied');
});

it('records an upload and asserts it happened', function () {
    $fake = fakeRemote();
    $host = RemoteHost::factory()->create();

    $fake->upload($host, 'file contents', '/etc/lanomat/config.yml');

    $fake->assertUploaded('/etc/lanomat/config.yml');
    expect($fake->uploads)->toHaveCount(1)
        ->and($fake->uploads[0]['host_id'])->toBe($host->id)
        ->and($fake->uploads[0]['remote_path'])->toBe('/etc/lanomat/config.yml')
        ->and($fake->uploads[0]['contents'])->toBe('file contents');
});

it('returns the configured nextProbe', function () {
    $fake = fakeRemote();
    $host = RemoteHost::factory()->create();

    $fake->nextProbe = new HostProbe(true, 'SHA256:abc123', null);

    $probe = $fake->probe($host);

    expect($probe)->toBe($fake->nextProbe)
        ->and($probe->reachable)->toBeTrue()
        ->and($probe->fingerprint)->toBe('SHA256:abc123');
});

it('assertNothingRan passes on a fresh fake', function () {
    $fake = fakeRemote();

    $fake->assertNothingRan();
});

it('assertRan fails when no matching command was run', function () {
    $fake = fakeRemote();
    $host = RemoteHost::factory()->create();

    $fake->run($host, 'echo hi');

    expect(fn () => $fake->assertRan('docker ps'))->toThrow(ExpectationFailedException::class);
});

it('records each run with the host id and command', function () {
    $fake = fakeRemote();
    $host = RemoteHost::factory()->create();

    $fake->run($host, 'uptime');

    expect($fake->commands)->toHaveCount(1)
        ->and($fake->commands[0]['host_id'])->toBe($host->id)
        ->and($fake->commands[0]['command'])->toBe('uptime');
});
