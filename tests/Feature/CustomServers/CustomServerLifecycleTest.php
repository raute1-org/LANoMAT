<?php

use App\Models\User;
use App\Modules\CustomServers\Actions\ProbeCustomServer;
use App\Modules\CustomServers\Actions\StartCustomServer;
use App\Modules\CustomServers\Actions\StopCustomServer;
use App\Modules\CustomServers\Enums\CustomServerStatus;
use App\Modules\CustomServers\Models\CustomServer;
use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('starts a custom server, running docker run with the image and container name, and flips status to Running', function () {
    $fake = fakeRemote();
    $host = RemoteHost::factory()->create();
    $server = CustomServer::factory()->for($host, 'host')->create([
        'image' => 'itzg/minecraft-server',
        'container_name' => 'mc-lan-1',
        'status' => CustomServerStatus::Stopped,
    ]);
    $actor = User::factory()->orga()->create();

    $started = app(StartCustomServer::class)->handle($server, $actor);

    $fake->assertRan('docker run');
    $fake->assertRan('itzg/minecraft-server');
    $fake->assertRan('mc-lan-1');
    expect($started->status)->toBe(CustomServerStatus::Running);
});

it('marks a custom server Failed and records stderr when the docker run exits non-zero', function () {
    $fake = fakeRemote();
    $fake->queueResult('docker run', new CommandResult(1, '', 'boom'));
    $host = RemoteHost::factory()->create();
    $server = CustomServer::factory()->for($host, 'host')->create([
        'status' => CustomServerStatus::Stopped,
    ]);
    $actor = User::factory()->orga()->create();

    $started = app(StartCustomServer::class)->handle($server, $actor);

    expect($started->status)->toBe(CustomServerStatus::Failed)
        ->and($started->last_output)->toBe('boom');
});

it('stops a custom server by running docker rm -f and setting status to Stopped', function () {
    $fake = fakeRemote();
    $host = RemoteHost::factory()->create();
    $server = CustomServer::factory()->for($host, 'host')->create([
        'container_name' => 'mc-lan-1',
        'status' => CustomServerStatus::Running,
    ]);
    $actor = User::factory()->orga()->create();

    $stopped = app(StopCustomServer::class)->handle($server, $actor);

    $fake->assertRan('docker rm -f');
    $fake->assertRan('mc-lan-1');
    expect($stopped->status)->toBe(CustomServerStatus::Stopped);
});

it('probes a custom server via docker inspect and reflects the running state', function () {
    $fake = fakeRemote();
    $fake->queueResult('docker inspect', new CommandResult(0, 'true', ''));
    $host = RemoteHost::factory()->create();
    $server = CustomServer::factory()->for($host, 'host')->create([
        'container_name' => 'mc-lan-1',
        'status' => CustomServerStatus::Stopped,
    ]);
    $actor = User::factory()->orga()->create();

    $probed = app(ProbeCustomServer::class)->handle($server, $actor);

    $fake->assertRan('docker inspect');
    $fake->assertRan("-f '{{.State.Running}}'");
    expect($probed->status)->toBe(CustomServerStatus::Running);
});

it('probes a custom server as Stopped when docker inspect reports not running', function () {
    $fake = fakeRemote();
    $fake->queueResult('docker inspect', new CommandResult(0, 'false', ''));
    $host = RemoteHost::factory()->create();
    $server = CustomServer::factory()->for($host, 'host')->create([
        'status' => CustomServerStatus::Running,
    ]);
    $actor = User::factory()->orga()->create();

    $probed = app(ProbeCustomServer::class)->handle($server, $actor);

    expect($probed->status)->toBe(CustomServerStatus::Stopped);
});

it('quotes an injection attempt in the command field as a single escapeshellarg token, never as bare shell metacharacters', function () {
    $fake = fakeRemote();
    $host = RemoteHost::factory()->create();
    $server = CustomServer::factory()->for($host, 'host')->create([
        'command' => '; rm -rf /',
    ]);
    $actor = User::factory()->orga()->create();

    app(StartCustomServer::class)->handle($server, $actor);

    $ran = collect($fake->commands)->last()['command'];

    expect($ran)->toContain(escapeshellarg('; rm -rf /'))
        ->not->toContain(' ; rm -rf / '); // a bare, unescaped break-out would appear as its own shell statement
});

it('forbids a participant from starting a custom server', function () {
    $host = RemoteHost::factory()->create();
    $server = CustomServer::factory()->for($host, 'host')->create();
    $actor = User::factory()->create();

    app(StartCustomServer::class)->handle($server, $actor);
})->throws(AuthorizationException::class);

it('has a German label for every CustomServerStatus case', function () {
    expect(CustomServerStatus::Stopped->label())->toBe('Gestoppt')
        ->and(CustomServerStatus::Starting->label())->toBe('Startet')
        ->and(CustomServerStatus::Running->label())->toBe('Läuft')
        ->and(CustomServerStatus::Failed->label())->toBe('Fehlgeschlagen');
});
