<?php

use App\Models\User;
use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Actions\CreatePermanentServer;
use App\Modules\GameServers\Domain\PelicanAllocation;
use App\Modules\GameServers\Domain\PelicanEgg;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Jobs\PollServerStatusJob;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\AllocationPlanner;
use App\Modules\GameServers\Support\PortPool;
use App\Modules\GameServers\Support\RepositoryEggs;
use App\Modules\GameServers\Testing\FakePelicanClient;
use Database\Seeders\GamesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([PollServerStatusJob::class]);
    config([
        'services.pelican.owner_user_id' => '3',
        'services.pelican.max_ram_mb' => 4096,
        'services.pelican.max_running_servers' => null,
        'services.pelican.max_servers_per_user' => null,
        'services.pelican.allocation_ip' => null,
    ]);
    $this->fake = fakePelican();
    $this->fake->eggs['5'] = new PelicanEgg('5', 'Mordhau', [
        'MAX_PLAYERS' => '48', 'QUERY_PORT' => '27015', 'SERVER_NAME' => 'x', 'DEFAULT_MAP' => 'Grad/FL_Grad', 'BEACON_PORT' => '15000', 'ADMIN_PASSWORD' => '',
    ], uuid: RepositoryEggs::MORDHAU_UUID);
    $this->seed(GamesSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->game = Game::query()->where('slug', 'mordhau')->firstOrFail();
});

it('seeds Mordhau with horde, deathmatch and frontline presets on the community egg', function () {
    foreach (['horde' => 'Grad/HRD_Grad', 'deathmatch' => 'Grad/FFA_Grad', 'frontline' => 'Grad/FL_Grad'] as $key => $map) {
        $preset = $this->game->findPreset($key);

        expect($preset)->not->toBeNull()
            ->and($preset->eggUuid)->toBe(RepositoryEggs::MORDHAU_UUID)
            ->and($preset->config->maxPlayers)->toBe(32)
            ->and($preset->config->memoryMb)->toBe(4096)
            ->and($preset->config->environment['DEFAULT_MAP'])->toBe($map)
            ->and($preset->config->fixedPorts)->toBe(['main' => 17777, 'QUERY_PORT' => 17778, 'BEACON_PORT' => 17779])
            ->and($preset->config->connectTemplate)->toBe('{ip}:{port}');
    }
});

it('provisions with the fixed ports, creating the missing allocations and no pool ports', function () {
    $link = app(CreatePermanentServer::class)->pelican($this->admin, $this->game, 'horde', 'Mordhau', null, null);

    $spec = $this->fake->created[0]['spec'];
    $byId = collect($this->fake->allocations)->keyBy('id');

    expect($this->fake->createdAllocations)->toHaveCount(1)
        ->and($this->fake->createdAllocations[0]['ports'])->toBe(['17777', '17778', '17779'])
        ->and($byId[$spec->allocationId]->port)->toBe(17777)
        ->and($spec->environment['QUERY_PORT'])->toBe('17778')
        ->and($spec->environment['BEACON_PORT'])->toBe('17779')
        ->and($spec->environment['MAX_PLAYERS'])->toBe('32')
        ->and($spec->limits->allocations)->toBe(3)
        ->and(collect($this->fake->allocations)->where('assigned', true)->pluck('port')->sort()->values()->all())->toBe([17777, 17778, 17779])
        ->and($link->preset_key)->toBe('horde');
});

it('reuses existing fixed allocations and only creates the missing ones', function () {
    $this->fake->allocations[] = new PelicanAllocation('900', '192.0.2.10', 17777);

    app(CreatePermanentServer::class)->pelican($this->admin, $this->game, 'horde', 'Mordhau', null, null);

    expect($this->fake->createdAllocations[0]['ports'])->toBe(['17778', '17779'])
        ->and($this->fake->created[0]['spec']->allocationId)->toBe('900');
});

it('refuses a second server with the same fixed ports with a readable error, not a crash', function () {
    $action = app(CreatePermanentServer::class);
    $action->pelican($this->admin, $this->game, 'horde', 'Mordhau A', null, null);

    expect(fn () => $action->pelican($this->admin, $this->game, 'deathmatch', 'Mordhau B', null, null))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.fixed_port_in_use');

    expect($this->fake->created)->toHaveCount(1)
        ->and(ServerLink::query()->count())->toBe(1)
        ->and(trans('gameservers.errors.fixed_port_in_use'))->not->toBe('gameservers.errors.fixed_port_in_use');
});

it('reports a port that is held by another panel server', function () {
    $this->fake->allocations[] = new PelicanAllocation('900', '192.0.2.10', 17778, null, true);

    expect(fn () => app(CreatePermanentServer::class)->pelican($this->admin, $this->game, 'horde', 'Mordhau', null, null))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.fixed_port_in_use');

    $this->fake->assertNothingCreated();
});

it('reports unavailable when the missing ports cannot be created', function () {
    $this->fake->failNextAllocationCreateWith(GameServerException::pelicanUnauthorized(403));

    expect(fn () => app(CreatePermanentServer::class)->pelican($this->admin, $this->game, 'horde', 'Mordhau', null, null))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.fixed_port_unavailable');
});

it('keeps pool-only presets unchanged and never hands a fixed port out of the pool', function () {
    $fake = new FakePelicanClient;

    foreach ([27015, 27016, 27017] as $i => $port) {
        $fake->allocations[] = new PelicanAllocation((string) ($i + 1), '192.0.2.10', $port);
    }

    $planner = new AllocationPlanner($fake, PortPool::parse('27015-27050'));

    expect(array_map(fn ($a) => $a->port, $planner->planFor('1', (new ServerConfig(extraPorts: ['TV_PORT']))->toArray())))->toBe([27015, 27016]);

    // Mixed: main fixed inside the pool, the extra variable comes from the pool without that port.
    $mixed = (new ServerConfig(extraPorts: ['TV_PORT'], fixedPorts: ['main' => 27015]))->toArray();

    expect(array_map(fn ($a) => $a->port, $planner->planFor('1', $mixed)))->toBe([27015, 27016]);
});

it('imports the Mordhau JSON egg through the import command', function () {
    $this->artisan('gameservers:import-eggs')->expectsOutputToContain('Mordhau')->assertExitCode(0);

    expect(collect($this->fake->importedEggs)->filter(fn ($c) => str_contains($c, RepositoryEggs::MORDHAU_UUID)))->toHaveCount(1);
});

it('reads the JSON egg through RepositoryEggs', function () {
    $egg = collect(RepositoryEggs::all())->firstWhere('uuid', RepositoryEggs::MORDHAU_UUID);

    expect($egg)->not->toBeNull()->and($egg['name'])->toBe('Mordhau')->and($egg['file'])->toBe('mordhau.json');
});
