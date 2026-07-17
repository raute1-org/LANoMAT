<?php

use App\Modules\Hosts\Enums\HostRole;
use App\Modules\Hosts\Enums\HostStatus;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Support\Facades\DB;

it('creates a remote host via the factory', function () {
    $host = RemoteHost::factory()->create(['name' => 'Lancache-01']);

    expect($host)->toBeInstanceOf(RemoteHost::class)
        ->and($host->name)->toBe('Lancache-01')
        ->and($host->role)->toBeInstanceOf(HostRole::class)
        ->and($host->status)->toBeInstanceOf(HostStatus::class);
});

it('round-trips the ssh_private_key through the encrypted cast', function () {
    $plaintext = 'PRIVATE-KEY-PEM';

    $host = RemoteHost::factory()->create(['ssh_private_key' => $plaintext]);
    $host->refresh();

    expect($host->ssh_private_key)->toBe($plaintext);
});

it('never stores the ssh_private_key as plaintext at rest', function () {
    $plaintext = 'PRIVATE-KEY-PEM';

    $host = RemoteHost::factory()->create(['ssh_private_key' => $plaintext]);

    $raw = DB::table('remote_hosts')->where('id', $host->id)->value('ssh_private_key');

    expect($raw)->not->toBe($plaintext);

    // The model attribute still decrypts back to the plaintext.
    expect($host->fresh()->ssh_private_key)->toBe($plaintext);
});

it('does not allow mass assignment of sensitive/system-managed fields', function () {
    $host = new RemoteHost;

    expect($host->getFillable())
        ->toContain('name')
        ->toContain('hostname')
        ->toContain('ssh_port')
        ->toContain('ssh_user')
        ->toContain('role')
        ->toContain('event_id')
        ->not->toContain('ssh_private_key')
        ->not->toContain('host_fingerprint')
        ->not->toContain('status')
        ->not->toContain('last_probed_at');
});

it('has German labels for HostRole', function () {
    expect(HostRole::Lancache->label())->toBe('LanCache')
        ->and(HostRole::GameServer->label())->toBe('Spielserver')
        ->and(HostRole::Generic->label())->toBe('Allgemein');
});

it('has German labels for HostStatus', function () {
    expect(HostStatus::Unknown->label())->toBe('Unbekannt')
        ->and(HostStatus::Reachable->label())->toBe('Erreichbar')
        ->and(HostStatus::Unreachable->label())->toBe('Nicht erreichbar');
});
