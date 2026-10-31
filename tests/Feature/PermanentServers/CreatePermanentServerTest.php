<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\CustomServers\Models\CustomServer;
use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Domain\ServerPreset;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Actions\CreatePermanentServer;
use App\Modules\GameServers\Contracts\MinecraftQuery;
use App\Modules\GameServers\Contracts\ServerQuery;
use App\Modules\GameServers\Domain\PelicanEgg;
use App\Modules\GameServers\Domain\PelicanNode;
use App\Modules\GameServers\Enums\QueryProtocol;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Jobs\PollServerStatusJob;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\GuardrailPolicy;
use App\Modules\GameServers\Testing\FakeMinecraftQuery;
use App\Modules\GameServers\Testing\FakeServerQuery;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([PollServerStatusJob::class]);
    config([
        'services.pelican.owner_user_id' => '3',
        'services.pelican.max_ram_mb' => 4096,
        'services.pelican.max_running_servers' => 1,
        'services.pelican.max_servers_per_user' => 1,
    ]);
    $this->fake = fakePelican();
    $this->fake->eggs['4'] = new PelicanEgg('4', 'Paper', ['MINECRAFT_VERSION' => 'latest', 'SERVER_JARFILE' => 'server.jar']);
    $this->fake->nodes = [
        new PelicanNode('1', 'venue', 'venue.example.test'),
        new PelicanNode('2', 'always-on', 'node2.example.test'),
        new PelicanNode('3', 'broken', 'node3.example.test', maintenanceMode: true),
    ];
    $this->admin = User::factory()->admin()->create();
    $this->minecraftQuery = new FakeMinecraftQuery;
    app()->instance(MinecraftQuery::class, $this->minecraftQuery);
    app()->instance(ServerQuery::class, new FakeServerQuery);
});

function minecraftGame(): Game
{
    return Game::query()->where('slug', 'minecraft')->first() ?? Game::factory()->create([
        'name' => 'Minecraft',
        'slug' => 'minecraft',
        'server_presets' => [new ServerPreset(
            key: 'paper',
            name: 'Paper',
            config: new ServerConfig(
                maxPlayers: 20,
                memoryMb: 2048,
                diskMb: 10240,
                environment: ['MINECRAFT_VERSION' => 'latest'],
                overridable: ['MINECRAFT_VERSION'],
                readinessType: 'minecraft',
                connectTemplate: '{ip}:{port}',
            ),
            eggName: 'Paper',
        )],
    ]);
}

it('creates a Pelican permanent server on the chosen node, independent of any event', function () {
    $link = app(CreatePermanentServer::class)->pelican($this->admin, minecraftGame(), 'paper', 'Survival', 'Whitelist über die Orga', '2', ['MINECRAFT_VERSION' => '1.21.4']);

    expect($link->permanent)->toBeTrue()
        ->and($link->source)->toBe(ServerSource::Pelican)
        ->and($link->pelican_node_id)->toBe('2')
        ->and($link->query_protocol)->toBe(QueryProtocol::Minecraft)
        ->and($link->status)->toBe(ServerLinkStatus::Provisioning)
        ->and($link->title)->toBe('Survival')
        ->and($link->description)->toBe('Whitelist über die Orga')
        ->and($link->event_id)->toBeNull()
        ->and($link->match_id)->toBeNull()
        ->and($link->tournament_id)->toBeNull()
        ->and($link->pelican_server_id)->not->toBeNull();

    $spec = $this->fake->created[0]['spec'];
    expect($spec->nodeId)->toBe('2')
        ->and($spec->environment['MINECRAFT_VERSION'])->toBe('1.21.4');

    Bus::assertDispatched(PollServerStatusJob::class);
});

it('falls back to the default node when none is chosen', function () {
    config(['services.pelican.default_node_id' => '1']);

    $link = app(CreatePermanentServer::class)->pelican($this->admin, minecraftGame(), 'paper', 'Survival', null, null);

    expect($link->pelican_node_id)->toBe('1')
        ->and($this->fake->created[0]['spec']->nodeId)->toBe('1');
});

it('refuses an unknown node and a node in maintenance without touching the panel', function (string $node, string $key) {
    expect(fn () => app(CreatePermanentServer::class)->pelican($this->admin, minecraftGame(), 'paper', 'Survival', null, $node))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === $key);

    $this->fake->assertNothingCreated();
    expect(ServerLink::query()->count())->toBe(0);
})->with([
    'unknown' => ['99', 'gameservers.permanent.errors.node_unknown'],
    'maintenance' => ['3', 'gameservers.permanent.errors.node_maintenance'],
]);

it('does not count against the LAN server caps, nor do the caps count permanent servers', function () {
    $game = minecraftGame();
    $action = app(CreatePermanentServer::class);

    // Caps are 1: two permanent servers still fit, and so does a regular event server afterwards.
    $action->pelican($this->admin, $game, 'paper', 'Survival A', null, '2');
    $action->pelican($this->admin, $game, 'paper', 'Survival B', null, '2');

    expect(ServerLink::query()->permanent()->count())->toBe(2);

    GuardrailPolicy::assertWithinLimits($game, [], $this->admin);
    expect(true)->toBeTrue();
});

it('drops the claimed row when creating on the panel fails', function () {
    $this->fake->failNextCreateWith(new RuntimeException('panel down'));

    expect(fn () => app(CreatePermanentServer::class)->pelican($this->admin, minecraftGame(), 'paper', 'Survival', null, '2'))
        ->toThrow(RuntimeException::class);

    expect(ServerLink::query()->count())->toBe(0);
});

it('registers an external server with a first status reading', function () {
    $this->minecraftQuery->willAnswer(FakeMinecraftQuery::serverInfo(players: 4, max: 20));

    $link = app(CreatePermanentServer::class)->external($this->admin, minecraftGame(), 'Cloud-Survival', 'Paper im Docker', '203.0.113.20', 25565, QueryProtocol::Minecraft);

    expect($link->permanent)->toBeTrue()
        ->and($link->source)->toBe(ServerSource::External)
        ->and($link->pelican_server_id)->toBeNull()
        ->and($link->custom_server_id)->toBeNull()
        ->and($link->status)->toBe(ServerLinkStatus::Ready)
        ->and($link->query_info['players'])->toBe(4)
        ->and($link->join_info->address)->toBe('203.0.113.20')
        ->and($link->join_info->connectString)->toBe('203.0.113.20:25565');

    $this->fake->assertNothingCreated();
});

it('shows an unreachable external server as offline instead of failing', function () {
    $link = app(CreatePermanentServer::class)->external($this->admin, minecraftGame(), 'Cloud-Survival', null, '203.0.113.20', 25565, QueryProtocol::Minecraft);

    expect($link->status)->toBe(ServerLinkStatus::Offline);
});

it('gives a Source server the steam link by default and assumes ready without a probe', function () {
    $source = app(CreatePermanentServer::class)->external($this->admin, minecraftGame(), 'InstaGib', null, '203.0.113.21', 7777, QueryProtocol::A2s);
    $plain = app(CreatePermanentServer::class)->external($this->admin, minecraftGame(), 'Ohne Abfrage', null, '203.0.113.22', 7777, null);

    expect($source->join_info->connectString)->toBeNull()
        ->and($plain->status)->toBe(ServerLinkStatus::Ready)
        ->and($plain->join_info->connectString)->toBe('203.0.113.22:7777');
});

it('rejects an empty address, a bad port and a missing title', function (string $address, int $port, string $title) {
    expect(fn () => app(CreatePermanentServer::class)->external($this->admin, minecraftGame(), $title, null, $address, $port, null))
        ->toThrow(GameServerException::class);

    expect(ServerLink::query()->count())->toBe(0);
})->with([
    'no address' => ['', 25565, 'X'],
    'port zero' => ['203.0.113.20', 0, 'X'],
    'port too high' => ['203.0.113.20', 70000, 'X'],
    'no title' => ['203.0.113.20', 25565, '  '],
]);

it('registers a Docker server with its container definition', function () {
    fakeRemote();
    $container = CustomServer::factory()->create(['remote_host_id' => RemoteHost::factory()->create()->id]);

    $link = app(CreatePermanentServer::class)->docker($this->admin, minecraftGame(), $container, 'Docker-Survival', null, '203.0.113.30', 25565, QueryProtocol::Minecraft);

    expect($link->source)->toBe(ServerSource::Docker)
        ->and($link->custom_server_id)->toBe($container->id)
        ->and($link->permanent)->toBeTrue();
});

it('refuses to create anything for anyone but an admin', function (string $role) {
    $user = User::factory()->state(['role' => Role::from($role)])->create();

    expect(fn () => app(CreatePermanentServer::class)->external($user, minecraftGame(), 'X', null, '203.0.113.20', 25565, null))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(CreatePermanentServer::class)->pelican($user, minecraftGame(), 'paper', 'X', null, null))
        ->toThrow(AuthorizationException::class);

    expect(ServerLink::query()->count())->toBe(0);
})->with(['participant', 'orga']);
