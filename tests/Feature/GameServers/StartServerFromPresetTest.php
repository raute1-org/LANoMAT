<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Domain\ServerPreset;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Actions\StartServerFromPreset;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\PelicanEgg;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Jobs\PollServerStatusJob;
use App\Modules\GameServers\Models\ServerLink;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

const TTT_EGG_UUID = '7b079b4f-ab83-40ef-98fc-059b4a270688';

beforeEach(function () {
    Bus::fake([PollServerStatusJob::class]);
    config(['services.pelican.owner_user_id' => '3', 'services.pelican.max_ram_mb' => 4096, 'services.pelican.max_running_servers' => null]);
    $this->fake = fakePelican();
    $this->fake->eggs['9'] = new PelicanEgg('9', 'Garrys Mod (LANoMAT)', ['GAMEMODE' => 'sandbox', 'SRCDS_MAP' => 'gm_flatgrass', 'MAX_PLAYERS' => '32', 'SERVER_HOSTNAME' => 'x', 'STEAM_WEB_API_KEY' => '', 'STEAM_ACC' => ''], uuid: TTT_EGG_UUID);
    $this->event = Event::factory()->create();
    $this->orga = User::factory()->orga()->create();
});

function tttGame(array $presetOverrides = [], ?ServerConfig $config = null): Game
{
    $fields = array_merge([
        'eggUuid' => TTT_EGG_UUID,
        'eggName' => 'Garrys Mod (LANoMAT)',
        'enabled' => true,
        'requires' => [],
    ], $presetOverrides);

    $preset = new ServerPreset(
        key: 'ttt',
        name: 'TTT',
        config: $config ?? new ServerConfig(
            maxPlayers: 16,
            memoryMb: 3072,
            diskMb: 20480,
            cpuPercent: 200,
            environment: ['GAMEMODE' => 'terrortown', 'SRCDS_MAP' => 'gm_construct'],
            overridable: ['SRCDS_MAP', 'MAX_PLAYERS'],
            readinessType: 'a2s',
            readinessGame: 'Trouble in Terrorist Town',
            connectTemplate: 'steam://connect/{ip}:{port}',
            installMarker: 'garrysmod',
        ),
        eggUuid: $fields['eggUuid'],
        eggName: $fields['eggName'],
        enabled: $fields['enabled'],
        requires: $fields['requires'],
    );

    return Game::factory()->create(['server_presets' => [$preset]]);
}

it('starts a server from a preset: egg by uuid, pool allocation, environment, link with snapshot', function () {
    $game = tttGame();

    $link = (new StartServerFromPreset(app(PelicanClient::class)))
        ->handle($this->event, $game, 'ttt', 'Sommer-LAN TTT', [], $this->orga);

    $spec = $this->fake->created[0]['spec'];

    expect($spec->eggId)->toBe('9')
        ->and($spec->allocationId)->toBe('1')
        ->and($spec->environment['GAMEMODE'])->toBe('terrortown')
        ->and($spec->environment['SRCDS_MAP'])->toBe('gm_construct')
        ->and($spec->environment['MAX_PLAYERS'])->toBe('16')
        ->and($spec->environment['SERVER_HOSTNAME'])->toBe('Sommer-LAN TTT')
        ->and($spec->limits->memory)->toBe(3072)
        ->and($link)->toBeInstanceOf(ServerLink::class)
        ->and($link->status)->toBe(ServerLinkStatus::Provisioning)
        ->and($link->event_id)->toBe($this->event->id)
        ->and($link->preset_key)->toBe('ttt')
        ->and($link->title)->toBe('Sommer-LAN TTT')
        ->and($link->requested_by)->toBe($this->orga->id)
        ->and($link->pelican_server_id)->not->toBeNull()
        ->and($link->readiness['type'])->toBe('a2s')
        ->and($link->readiness['install_marker'])->toBe('garrysmod');

    Bus::assertDispatched(PollServerStatusJob::class);
});

it('lets the orga override only the keys the preset marks overridable', function () {
    $game = tttGame();

    (new StartServerFromPreset($this->fake))->handle($this->event, $game, 'ttt', 'x', ['SRCDS_MAP' => 'ttt_minecraft_b5'], $this->orga);

    expect($this->fake->created[0]['spec']->environment['SRCDS_MAP'])->toBe('ttt_minecraft_b5');

    expect(fn () => (new StartServerFromPreset($this->fake))->handle($this->event, $game, 'ttt', 'x', ['GAMEMODE' => 'sandbox'], $this->orga))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.override_not_allowed');

    expect($this->fake->created)->toHaveCount(1);
});

it('refuses a disabled preset', function () {
    $game = tttGame(['enabled' => false]);

    expect(fn () => (new StartServerFromPreset($this->fake))->handle($this->event, $game, 'ttt', 'x', [], $this->orga))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.preset_unavailable_disabled');

    $this->fake->assertNothingCreated();
    expect(ServerLink::query()->count())->toBe(0);
});

it('keeps a preset that requires the Steam Web API key locked until one is configured', function () {
    $game = tttGame(['requires' => ['authkey']]);

    expect(fn () => (new StartServerFromPreset($this->fake))->handle($this->event, $game, 'ttt', 'x', [], $this->orga))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.preset_unavailable_requires_authkey');

    config(['services.pelican.steam_web_api_key' => str_repeat('K', 32)]);

    (new StartServerFromPreset($this->fake))->handle($this->event, $game, 'ttt', 'x', [], $this->orga);

    expect($this->fake->created[0]['spec']->environment['STEAM_WEB_API_KEY'])->toBe(str_repeat('K', 32));
});

it('fails with a German-keyed error and marks the link failed when the egg is not imported', function () {
    $this->fake->eggs = [];
    $game = tttGame();

    expect(fn () => (new StartServerFromPreset($this->fake))->handle($this->event, $game, 'ttt', 'x', [], $this->orga))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.egg_not_imported');

    expect(ServerLink::query()->sole()->status)->toBe(ServerLinkStatus::Failed);
});

it('returns the typed noFreeAllocation error when the port pool is exhausted', function () {
    $this->fake->allocations = [];
    $game = tttGame();

    expect(fn () => (new StartServerFromPreset($this->fake))->handle($this->event, $game, 'ttt', 'x', [], $this->orga))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.no_free_allocation');

    expect(ServerLink::query()->sole()->status)->toBe(ServerLinkStatus::Failed);
});

it('reserves one extra pool allocation per declared extra port', function () {
    $this->fake->eggs['9'] = new PelicanEgg('9', 'Garrys Mod (LANoMAT)', ['TV_PORT' => '27020'], uuid: TTT_EGG_UUID);
    $game = tttGame(config: new ServerConfig(extraPorts: ['TV_PORT'], memoryMb: 2048));

    (new StartServerFromPreset($this->fake))->handle($this->event, $game, 'ttt', 'x', [], $this->orga);

    $spec = $this->fake->created[0]['spec'];
    expect($spec->additionalAllocationIds)->toBe(['2'])
        ->and($spec->environment['TV_PORT'])->toBe('27016');
});

it('only lets orga start servers, even when the action is called directly', function () {
    $game = tttGame();

    expect(fn () => (new StartServerFromPreset($this->fake))->handle($this->event, $game, 'ttt', 'x', [], User::factory()->create()))
        ->toThrow(AuthorizationException::class);

    $this->fake->assertNothingCreated();
});

it('reports an unknown preset key', function () {
    $game = tttGame();

    expect(fn () => (new StartServerFromPreset($this->fake))->handle($this->event, $game, 'nope', 'x', [], $this->orga))
        ->toThrow(fn (GameServerException $e) => $e->translationKey === 'gameservers.errors.preset_not_found');
});
