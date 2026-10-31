<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\CustomServers\Models\CustomServer;
use App\Modules\GameServers\Actions\DeletePermanentServer;
use App\Modules\GameServers\Actions\PowerPermanentServer;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Testing\FakePelicanClient;
use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->pelican = fakePelican();
    $this->remote = fakeRemote();
    $this->admin = User::factory()->admin()->create();
});

function dockerPermanent(): ServerLink
{
    $container = CustomServer::factory()->create(['remote_host_id' => RemoteHost::factory()->create()->id, 'container_name' => 'mc-survival']);

    return ServerLink::factory()->permanent(ServerSource::Docker)->create(['custom_server_id' => $container->id]);
}

it('powers a Pelican permanent server through the panel and shows a stop as offline at once', function () {
    $server = $this->pelican->createServer(FakePelicanClient::makeSpec('egg-1'));
    $link = ServerLink::factory()->permanent(ServerSource::Pelican)->create([
        'pelican_server_id' => $server->id,
        'pelican_server_identifier' => $server->identifier,
    ]);

    app(PowerPermanentServer::class)->handle($this->admin, $link, PowerAction::Restart);
    $this->pelican->assertPowerAction($server->identifier, PowerAction::Restart);
    expect($link->fresh()->status)->toBe(ServerLinkStatus::Ready);

    app(PowerPermanentServer::class)->handle($this->admin, $link, PowerAction::Stop);
    expect($link->fresh()->status)->toBe(ServerLinkStatus::Offline);
});

it('starts, stops and restarts a Docker server without destroying the container', function (PowerAction $action, string $command) {
    $link = dockerPermanent();

    app(PowerPermanentServer::class)->handle($this->admin, $link, $action);

    $this->remote->assertRan($command." 'mc-survival'");
    expect(collect($this->remote->commands)->contains(fn ($c) => str_contains($c['command'], 'docker rm')))->toBeFalse();
})->with([
    'start' => [PowerAction::Start, 'docker start'],
    'stop' => [PowerAction::Stop, 'docker stop'],
    'restart' => [PowerAction::Restart, 'docker restart'],
]);

it('creates the container from its definition when it does not exist yet', function () {
    $link = dockerPermanent();
    $this->remote->queueResult('docker start', new CommandResult(1, '', 'Error response from daemon: No such container: mc-survival'));

    app(PowerPermanentServer::class)->handle($this->admin, $link, PowerAction::Start);

    $this->remote->assertRan('docker run -d --name');
});

it('reports a failing Docker command instead of pretending it worked', function () {
    $link = dockerPermanent();
    $this->remote->queueResult('docker stop', new CommandResult(1, '', 'permission denied'));

    expect(fn () => app(PowerPermanentServer::class)->handle($this->admin, $link, PowerAction::Stop))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.permanent.errors.docker_unavailable');

    expect($link->fresh()->status)->toBe(ServerLinkStatus::Ready);
});

it('cannot control an external server', function () {
    $link = ServerLink::factory()->permanent(ServerSource::External)->create();

    expect(fn () => app(PowerPermanentServer::class)->handle($this->admin, $link, PowerAction::Start))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.permanent.errors.external_not_controllable');

    $this->remote->assertNothingRan();
    expect($this->pelican->powerActions)->toBe([]);
});

it('deletes a Pelican server on the panel before removing the entry, and keeps the entry when that fails', function () {
    $server = $this->pelican->createServer(FakePelicanClient::makeSpec('egg-1'));
    $link = ServerLink::factory()->permanent(ServerSource::Pelican)->create(['pelican_server_id' => $server->id]);

    app(DeletePermanentServer::class)->handle($this->admin, $link);

    $this->pelican->assertServerDeleted($server->id);
    expect(ServerLink::query()->count())->toBe(0);
});

it('only removes the entry of a Docker or external server, the server keeps running', function (ServerSource $source) {
    $link = $source === ServerSource::Docker ? dockerPermanent() : ServerLink::factory()->permanent($source)->create();

    app(DeletePermanentServer::class)->handle($this->admin, $link);

    expect(ServerLink::query()->count())->toBe(0)
        ->and($this->pelican->deleted)->toBe([]);
    $this->remote->assertNothingRan();
    expect(CustomServer::query()->count())->toBe($source === ServerSource::Docker ? 1 : 0);
})->with([ServerSource::Docker, ServerSource::External]);

it('refuses power and delete for everyone but an admin', function (string $role) {
    $user = User::factory()->state(['role' => Role::from($role)])->create();
    $link = ServerLink::factory()->permanent(ServerSource::Docker)->create();

    expect(fn () => app(PowerPermanentServer::class)->handle($user, $link, PowerAction::Stop))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeletePermanentServer::class)->handle($user, $link))->toThrow(AuthorizationException::class);

    expect($link->fresh())->not->toBeNull();
    $this->remote->assertNothingRan();
})->with(['participant', 'helper', 'orga']);

it('refuses to delete a non-permanent link through the permanent action', function () {
    $link = ServerLink::factory()->create(['status' => ServerLinkStatus::Ready]);

    expect(fn () => app(DeletePermanentServer::class)->handle($this->admin, $link))->toThrow(GameServerException::class);
    expect($link->fresh())->not->toBeNull();
});
