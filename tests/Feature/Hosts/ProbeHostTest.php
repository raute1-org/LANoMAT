<?php

use App\Modules\Hosts\Actions\ProbeHost;
use App\Modules\Hosts\Domain\HostProbe;
use App\Modules\Hosts\Enums\HostStatus;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks a reachable host Reachable, pins the fingerprint, and stamps last_probed_at', function () {
    $fake = fakeRemote();
    $fake->nextProbe = new HostProbe(true, 'SHA256:freshfingerprint', null);

    $host = RemoteHost::factory()->create(['host_fingerprint' => null, 'status' => HostStatus::Unknown, 'last_probed_at' => null]);

    $probed = app(ProbeHost::class)->handle($host);

    expect($probed->status)->toBe(HostStatus::Reachable)
        ->and($probed->host_fingerprint)->toBe('SHA256:freshfingerprint')
        ->and($probed->last_probed_at)->not->toBeNull();

    $fresh = $host->fresh();
    expect($fresh->status)->toBe(HostStatus::Reachable)
        ->and($fresh->host_fingerprint)->toBe('SHA256:freshfingerprint')
        ->and($fresh->last_probed_at)->not->toBeNull();
});

it('does not overwrite an already-pinned host_fingerprint', function () {
    $fake = fakeRemote();
    $fake->nextProbe = new HostProbe(true, 'SHA256:differentfingerprint', null);

    $host = RemoteHost::factory()->create(['host_fingerprint' => 'SHA256:originalfingerprint']);

    $probed = app(ProbeHost::class)->handle($host);

    expect($probed->host_fingerprint)->toBe('SHA256:originalfingerprint');
});

it('marks an unreachable host Unreachable', function () {
    $fake = fakeRemote();
    $fake->nextProbe = new HostProbe(false, null, 'connection refused');

    $host = RemoteHost::factory()->create(['status' => HostStatus::Unknown]);

    $probed = app(ProbeHost::class)->handle($host);

    expect($probed->status)->toBe(HostStatus::Unreachable)
        ->and($probed->host_fingerprint)->toBeNull();
});

it('has a German label for the Unreachable status after probing', function () {
    $fake = fakeRemote();
    $fake->nextProbe = new HostProbe(false, null, 'timeout');

    $host = RemoteHost::factory()->create();

    $probed = app(ProbeHost::class)->handle($host);

    expect($probed->status->label())->toBe('Nicht erreichbar');
});
