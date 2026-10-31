<?php

use App\Modules\CustomServers\Models\CustomServer;
use App\Modules\GameServers\Contracts\MinecraftQuery;
use App\Modules\GameServers\Contracts\ServerQuery;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\QueryProtocol;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Testing\FakeMinecraftQuery;
use App\Modules\GameServers\Testing\FakeServerQuery;
use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Exceptions\RemoteExecutionException;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mc = new FakeMinecraftQuery;
    $this->a2s = new FakeServerQuery;
    app()->instance(MinecraftQuery::class, $this->mc);
    app()->instance(ServerQuery::class, $this->a2s);
    $this->pelican = fakePelican();
    $this->remote = fakeRemote();
});

function permanentLink(ServerSource $source, array $attributes = []): ServerLink
{
    return ServerLink::factory()->permanent($source)->create($attributes + [
        'query_protocol' => QueryProtocol::Minecraft,
    ]);
}

function refreshPermanent(): void
{
    test()->artisan('gameservers:refresh-permanent')->assertSuccessful();
}

it('marks an answering external server ready and stores the players online', function () {
    $link = permanentLink(ServerSource::External, ['status' => ServerLinkStatus::Offline]);
    $this->mc->willAnswer(FakeMinecraftQuery::serverInfo(players: 7, max: 20));

    refreshPermanent();

    $link->refresh();
    expect($link->status)->toBe(ServerLinkStatus::Ready)
        ->and($link->query_info['players'])->toBe(7)
        ->and($link->queried_at)->not->toBeNull()
        ->and($this->mc->queries[0])->toBe(['host' => '203.0.113.10', 'port' => 25565]);
});

it('uses the A2S query for a Source server', function () {
    $link = permanentLink(ServerSource::External, ['query_protocol' => QueryProtocol::A2s]);
    $this->a2s->willAnswer(FakeServerQuery::serverInfo(name: 'InstaGib', players: 3, max: 16));

    refreshPermanent();

    expect($link->refresh()->query_info['players'])->toBe(3)
        ->and($this->mc->queries)->toBe([]);
});

it('shows an unreachable external server as offline, never failing', function () {
    $link = permanentLink(ServerSource::External);

    refreshPermanent();

    expect($link->refresh()->status)->toBe(ServerLinkStatus::Offline);
});

it('tolerates a single missed query but flips after three silent minutes', function () {
    $link = permanentLink(ServerSource::External, ['queried_at' => now()->subMinute(), 'query_info' => ['players' => 2, 'max_players' => 20]]);

    refreshPermanent();
    expect($link->refresh()->status)->toBe(ServerLinkStatus::Ready);

    $link->queried_at = now()->subMinutes(4);
    $link->save();

    refreshPermanent();
    expect($link->refresh()->status)->toBe(ServerLinkStatus::Offline)
        ->and($link->query_info)->toBeNull();
});

it('assumes a server without a query protocol is ready', function () {
    $link = permanentLink(ServerSource::External, ['query_protocol' => null, 'status' => ServerLinkStatus::Offline]);

    refreshPermanent();

    expect($link->refresh()->status)->toBe(ServerLinkStatus::Ready)
        ->and($this->mc->queries)->toBe([])
        ->and($this->a2s->queries)->toBe([]);
});

it('reads a Pelican server\'s power state: stopped is offline, running and silent is not responding', function () {
    $server = $this->pelican->createServer(\App\Modules\GameServers\Testing\FakePelicanClient::makeSpec());
    $link = permanentLink(ServerSource::Pelican, ['pelican_server_id' => $server->id]);

    $this->pelican->setState($server->id, ServerState::Stopped);
    refreshPermanent();
    expect($link->refresh()->status)->toBe(ServerLinkStatus::Offline)
        ->and($this->mc->queries)->toBe([]);

    $this->pelican->setState($server->id, ServerState::Running);
    refreshPermanent();
    expect($link->refresh()->status)->toBe(ServerLinkStatus::NotResponding);

    $this->mc->willAnswer(FakeMinecraftQuery::serverInfo(players: 1));
    refreshPermanent();
    expect($link->refresh()->status)->toBe(ServerLinkStatus::Ready);
});

it('leaves a Pelican server alone while it installs, and survives an unknown panel server', function () {
    $server = $this->pelican->createServer(\App\Modules\GameServers\Testing\FakePelicanClient::makeSpec());
    $installing = permanentLink(ServerSource::Pelican, ['pelican_server_id' => $server->id, 'status' => ServerLinkStatus::Provisioning]);
    $gone = permanentLink(ServerSource::Pelican, ['pelican_server_id' => '999', 'status' => ServerLinkStatus::Offline]);
    $this->mc->willAnswer(FakeMinecraftQuery::serverInfo());

    refreshPermanent();

    expect($installing->refresh()->status)->toBe(ServerLinkStatus::Provisioning)
        // The fake throws for an unknown id; the run continued and the query decided.
        ->and($gone->refresh()->status)->toBe(ServerLinkStatus::Ready);
});

it('asks the Docker host whether the container runs', function () {
    $container = CustomServer::factory()->create(['remote_host_id' => RemoteHost::factory()->create()->id, 'container_name' => 'mc-survival']);
    $link = permanentLink(ServerSource::Docker, ['custom_server_id' => $container->id]);

    $this->remote->queueResult('docker inspect', new CommandResult(0, "false\n", ''));
    refreshPermanent();
    expect($link->refresh()->status)->toBe(ServerLinkStatus::Offline);

    $this->remote->queueResult('docker inspect', new CommandResult(0, "true\n", ''));
    $this->mc->willAnswer(FakeMinecraftQuery::serverInfo(players: 2));
    refreshPermanent();
    expect($link->refresh()->status)->toBe(ServerLinkStatus::Ready);

    $this->remote->assertRan("docker inspect -f '{{.State.Running}}' 'mc-survival'");
});

it('treats a missing container as offline and an unreachable host as unknown', function () {
    $container = CustomServer::factory()->create(['remote_host_id' => RemoteHost::factory()->create()->id]);
    $link = permanentLink(ServerSource::Docker, ['custom_server_id' => $container->id]);

    $this->remote->queueResult('docker inspect', new CommandResult(1, '', 'Error: No such object: x'));
    refreshPermanent();
    expect($link->refresh()->status)->toBe(ServerLinkStatus::Offline);

    // Host cannot be reached: nothing is known about the container, the game server's own answer decides.
    app()->instance(\App\Modules\Hosts\Contracts\RemoteExecutor::class, new class extends \App\Modules\Hosts\Testing\FakeRemoteExecutor
    {
        public function run(RemoteHost $host, string $command): CommandResult
        {
            throw RemoteExecutionException::commandFailed();
        }
    });
    $this->mc->willAnswer(FakeMinecraftQuery::serverInfo(players: 5));

    refreshPermanent();
    expect($link->refresh()->status)->toBe(ServerLinkStatus::Ready);
});

it('keeps going when refreshing one server throws', function () {
    $first = permanentLink(ServerSource::External, ['title' => 'A', 'status' => ServerLinkStatus::Offline]);
    $second = permanentLink(ServerSource::External, ['title' => 'B', 'status' => ServerLinkStatus::Offline]);

    app()->instance(\App\Modules\GameServers\Support\PermanentServerRefresher::class, new class(app(\App\Modules\GameServers\Contracts\PelicanClient::class), $this->a2s, new FakeMinecraftQuery(FakeMinecraftQuery::serverInfo()), app(\App\Modules\GameServers\Support\DockerServerControl::class)) extends \App\Modules\GameServers\Support\PermanentServerRefresher
    {
        public function refresh(ServerLink $link): void
        {
            if ($link->title === 'A') {
                throw new RuntimeException('boom');
            }

            parent::refresh($link);
        }
    });

    refreshPermanent();

    expect($first->refresh()->status)->toBe(ServerLinkStatus::Offline)
        ->and($second->refresh()->status)->toBe(ServerLinkStatus::Ready);
});

it('is scheduled every minute without overlapping, behind the module toggle', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command, 'gameservers:refresh-permanent'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});

it('is not touched by the match/event query refresh', function () {
    $link = permanentLink(ServerSource::Pelican, [
        'readiness' => ['type' => 'a2s'],
        'join_info' => new JoinInfo(address: '203.0.113.10', port: 27015),
    ]);
    $this->a2s->willAnswer(FakeServerQuery::serverInfo());

    $this->artisan('gameservers:refresh-queries')->assertSuccessful();

    expect($this->a2s->queries)->toBe([])
        ->and($link->refresh()->queried_at)->toBeNull();
});
