<?php

use App\Models\User;
use App\Modules\Assistant\Enums\AssistantActionStatus;
use App\Modules\Assistant\Models\AssistantAction;
use App\Modules\Assistant\Support\ToolRegistry;
use App\Modules\Assistant\Tools\Write\ServerChangeMapTool;
use App\Modules\Assistant\Tools\Write\ServerConsoleTool;
use App\Modules\Assistant\Tools\Write\ServerCreatePermanentTool;
use App\Modules\Assistant\Tools\Write\ServerPowerTool;
use App\Modules\Assistant\Tools\Write\ServerRestartRoundTool;
use App\Modules\Assistant\Tools\Write\ServerSettingsUpdateTool;
use App\Modules\Assistant\Tools\Write\ServerStartPresetTool;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Domain\ServerPreset;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Contracts\RemoteDockerControl;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Domain\PelicanEgg;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\Jobs\PollServerStatusJob;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Testing\FakeRemoteDockerControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

const ASSISTANT_EGG_UUID = '7b079b4f-ab83-40ef-98fc-059b4a270688';

function assistantGame(): Game
{
    return Game::factory()->create([
        'name' => 'Garrys Mod',
        'server_presets' => [new ServerPreset(
            key: 'ttt',
            name: 'TTT',
            config: new ServerConfig(
                maxPlayers: 16, memoryMb: 3072, diskMb: 20480, cpuPercent: 200,
                environment: ['GAMEMODE' => 'terrortown', 'SRCDS_MAP' => 'gm_construct'],
                overridable: ['SRCDS_MAP', 'MAX_PLAYERS'],
                readinessType: 'a2s', readinessGame: 'Trouble in Terrorist Town', connectTemplate: 'steam://connect/{ip}:{port}', installMarker: 'garrysmod',
            ),
            eggUuid: ASSISTANT_EGG_UUID, eggName: 'Garrys Mod (LANoMAT)', enabled: true, requires: [],
        )],
    ]);
}

beforeEach(function () {
    Bus::fake([PollServerStatusJob::class]);
    config(['services.pelican.owner_user_id' => '3', 'services.pelican.max_ram_mb' => 4096, 'services.pelican.max_running_servers' => null]);
    $this->pelican = fakePelican();
    $this->pelican->eggs['9'] = new PelicanEgg('9', 'Garrys Mod (LANoMAT)', ['GAMEMODE' => '', 'SRCDS_MAP' => '', 'MAX_PLAYERS' => '', 'SERVER_HOSTNAME' => '', 'STEAM_WEB_API_KEY' => '', 'STEAM_ACC' => ''], uuid: ASSISTANT_EGG_UUID);
    $this->pelican->eggs['5'] = new PelicanEgg('5', 'CS2', array_fill_keys(['SERVER_NAME', 'SRCDS_MAP', 'GAME_TYPE', 'GAME_MODE', 'MAX_PLAYERS', 'SERVER_PASSWORD', 'HOST_WORKSHOP_MAP'], ''));
    $this->orga = User::factory()->orga()->create();
    $this->admin = User::factory()->admin()->create();
});

function cs2Link(): ServerLink
{
    test()->pelican->addServer('7', 'abcd1234', ['SERVER_NAME' => 'Old', 'SRCDS_MAP' => 'de_dust2', 'GAME_TYPE' => '0', 'GAME_MODE' => '1', 'MAX_PLAYERS' => '10', 'SERVER_PASSWORD' => 'oldsecret']);

    return ServerLink::factory()->permanent(ServerSource::External)->create([
        'title' => 'CS2 Competitive', 'pelican_server_id' => '7', 'pelican_server_identifier' => 'abcd1234', 'join_info' => new JoinInfo('203.0.113.5', 27015, 'oldsecret'),
    ]);
}

it('starts an event server from a preset', function () {
    $event = Event::factory()->create();
    $args = ['event_id' => $event->id, 'game_id' => assistantGame()->id, 'preset_key' => 'ttt', 'title' => 'TTT Abend'];

    $out = json_decode(runApproved(ServerStartPresetTool::class, $this->orga, $args), true);

    expect($out['started'])->toBeTrue()
        ->and(ServerLink::query()->findOrFail($out['server_id'])->event_id)->toBe($event->id);
});

it('refuses a secret-looking override before any card', function () {
    $args = ['event_id' => Event::factory()->create()->id, 'game_id' => assistantGame()->id, 'preset_key' => 'ttt', 'overrides' => [['key' => 'SERVER_PASSWORD', 'value' => 'x']]];
    $tool = new ServerStartPresetTool($this->orga);

    expect(approvalFor($tool, $args))->toBeNull()
        ->and((string) $tool->handle(new Request($args, 'c')))->toBe(__('assistant.password_via_card'));
});

it('refuses an unknown preset and a not allowed override with a readable answer', function () {
    $game = assistantGame();
    $event = Event::factory()->create();
    $tool = new ServerStartPresetTool($this->orga);

    expect((string) $tool->handle(new Request(['event_id' => $event->id, 'game_id' => $game->id, 'preset_key' => 'nope'], 'c')))->toStartWith('Not found')
        ->and((string) $tool->handle(new Request(['event_id' => $event->id, 'game_id' => $game->id, 'preset_key' => 'ttt', 'overrides' => [['key' => 'GAMEMODE', 'value' => 'sandbox']]], 'c')))->toContain('überschrieben');
});

it('lets an admin create a permanent server but gives an orga no card (Review Focus 4)', function () {
    $game = assistantGame();
    $args = ['game_id' => $game->id, 'preset_key' => 'ttt', 'title' => 'Dauer-TTT'];

    expect(approvalFor(new ServerCreatePermanentTool($this->orga), $args))->toBeNull()
        ->and((string) (new ServerCreatePermanentTool($this->orga))->handle(new Request($args, 'c')))->toStartWith('Forbidden')
        ->and(ServerLink::query()->count())->toBe(0);

    $out = json_decode(runApproved(ServerCreatePermanentTool::class, $this->admin, $args), true);

    expect($out['created'])->toBeTrue()->and(ServerLink::query()->where('permanent', true)->count())->toBe(1);
});

it('fails the row with a readable Forbidden when the right is gone at approval time', function () {
    $args = ['game_id' => assistantGame()->id, 'preset_key' => 'ttt', 'title' => 'Dauer-TTT'];

    $out = runApproved(ServerCreatePermanentTool::class, $this->orga, $args);

    expect($out)->toStartWith('Forbidden')
        ->and(AssistantAction::query()->firstOrFail()->status)->toBe(AssistantActionStatus::Failed)
        ->and(ServerLink::query()->count())->toBe(0);
});

it('passes the card-entered password to Pelican and keeps it out of the preview, rows and audit', function () {
    $link = cs2Link();
    $args = ['server_id' => $link->id, 'map' => 'de_mirage', 'max_players' => 10, 'password_action' => 'set'];
    $tool = new ServerSettingsUpdateTool($this->orga);

    $preview = $tool->preview($args);
    $out = runApproved(ServerSettingsUpdateTool::class, $this->orga, $args, secret: 'lan2026');

    $env = $this->pelican->getServer('7')->meta['container']['environment'];
    $row = AssistantAction::query()->firstOrFail();

    expect($preview->needsSecret)->toBeTrue()
        ->and(json_encode($preview->toArray()))->not->toContain('lan2026')
        ->and(collect($preview->rows)->pluck('value')->all())->toContain('wird auf der Karte gesetzt', 'de_dust2 → de_mirage')
        ->and($env['SERVER_PASSWORD'])->toBe('lan2026')->and($env['SRCDS_MAP'])->toBe('de_mirage')->and($env['SERVER_NAME'])->toBe('Old')
        ->and($out)->not->toContain('lan2026')
        ->and($row->status)->toBe(AssistantActionStatus::Executed)
        ->and($row->secret)->toBeNull()
        ->and(json_encode($row->getAttributes()))->not->toContain('lan2026');
});

it('clears a password and keeps it when asked to keep', function () {
    $link = cs2Link();

    runApproved(ServerSettingsUpdateTool::class, $this->orga, ['server_id' => $link->id, 'max_players' => 8, 'password_action' => 'keep'], 'c1');
    expect($this->pelican->getServer('7')->meta['container']['environment']['SERVER_PASSWORD'])->toBe('oldsecret');

    runApproved(ServerSettingsUpdateTool::class, $this->orga, ['server_id' => $link->id, 'password_action' => 'clear'], 'c2');
    expect($this->pelican->getServer('7')->meta['container']['environment']['SERVER_PASSWORD'])->toBe('');
});

it('fails without changing anything when the card password is missing', function () {
    $link = cs2Link();

    $out = runApproved(ServerSettingsUpdateTool::class, $this->orga, ['server_id' => $link->id, 'password_action' => 'set']);

    expect($out)->toBe(__('assistant.password_missing'))
        ->and($this->pelican->getServer('7')->meta['container']['environment']['SERVER_PASSWORD'])->toBe('oldsecret')
        ->and(AssistantAction::query()->firstOrFail()->status)->toBe(AssistantActionStatus::Failed);
});

it('has no password field in any tool schema', function () {
    $schema = app(JsonSchemaTypeFactory::class);

    foreach (ToolRegistry::classes() as $class) {
        $keys = array_keys((new $class($this->orga))->schema($schema));

        expect(collect($keys)->filter(fn ($k) => preg_match('/pass|secret|token/i', $k) === 1 && $k !== 'password_action')->all())->toBe([], $class);
    }
});

it('refuses console commands that smuggle a secret before any card (Review Focus 5)', function (string $command) {
    $link = ServerLink::factory()->remoteDocker()->create();
    $tool = new ServerConsoleTool($this->orga);
    $args = ['server_id' => $link->id, 'command' => $command];

    expect(approvalFor($tool, $args))->toBeNull()
        ->and((string) $tool->handle(new Request($args, 'c')))->toBe(__('assistant.password_via_card'));
    // And even an approved row would not run it.
    expect(runApproved(ServerConsoleTool::class, $this->orga, $args))->toBe(__('assistant.password_via_card'));
})->with(['sv_password x; status', '  RCON_PASSWORD y', 'status;sv_password z', 'sm_cvar "sv_password" q', 'sv_setsteamaccount ABC']);

it('shows a console command verbatim and runs it after approval, scrubbing the answer', function () {
    $fake = new FakeRemoteDockerControl;
    $fake->commandOutput = 'ok; token=abc123';
    app()->instance(RemoteDockerControl::class, $fake);
    $link = ServerLink::factory()->remoteDocker()->create();
    $args = ['server_id' => $link->id, 'command' => 'mp_restartgame 1'];

    $preview = (new ServerConsoleTool($this->orga))->preview($args)->toArray();
    $out = runApproved(ServerConsoleTool::class, $this->orga, $args);

    expect($preview['danger'])->toBeTrue()
        ->and(collect($preview['rows'])->firstWhere('label', 'Befehl'))->toMatchArray(['value' => 'mp_restartgame 1', 'mono' => true])
        ->and($fake->commands[0]['command'])->toBe('mp_restartgame 1')
        ->and($out)->not->toContain('abc123');
});

it('only sends console commands to remote docker servers', function () {
    $link = cs2Link();

    expect((string) (new ServerConsoleTool($this->orga))->handle(new Request(['server_id' => $link->id, 'command' => 'status'], 'c')))->toContain('remote Docker');
});

it('powers an event server only after the power policy passes (participant is refused)', function () {
    $this->pelican->addServer('11', 'evt11111', [], state: ServerState::Running);
    $link = ServerLink::factory()->create(['pelican_server_id' => '11', 'pelican_server_identifier' => 'evt11111']);
    $args = ['server_id' => $link->id, 'action' => 'stop'];

    expect(runApproved(ServerPowerTool::class, User::factory()->create(), $args))->toStartWith('Forbidden')
        ->and($this->pelican->powerActions)->toBe([]);

    runApproved(ServerPowerTool::class, $this->orga, $args, 'call_2');
    expect($this->pelican->powerActions)->toHaveCount(1)
        ->and((new ServerPowerTool($this->orga))->preview($args)->danger)->toBeTrue()
        ->and((new ServerPowerTool($this->orga))->preview(['server_id' => $link->id, 'action' => 'start'])->danger)->toBeFalse();
});

it('powers a permanent server through the configure actions, and a remote container through its control', function () {
    $link = cs2Link();
    runApproved(ServerPowerTool::class, $this->orga, ['server_id' => $link->id, 'action' => 'restart']);
    expect($this->pelican->powerActions)->toHaveCount(1);

    $fake = new FakeRemoteDockerControl;
    app()->instance(RemoteDockerControl::class, $fake);
    $remote = ServerLink::factory()->remoteDocker()->create();
    runApproved(ServerPowerTool::class, $this->orga, ['server_id' => $remote->id, 'action' => 'stop'], 'call_2');
    expect($fake->powered[0]['action'])->toBe(PowerAction::Stop->value);
});

it('changes the map and restarts the round on a running permanent server', function () {
    $link = cs2Link();

    runApproved(ServerChangeMapTool::class, $this->orga, ['server_id' => $link->id, 'map' => 'de_inferno']);
    runApproved(ServerRestartRoundTool::class, $this->orga, ['server_id' => $link->id], 'call_2');

    expect(collect($this->pelican->commands)->flatten()->implode('|'))->toContain('changelevel de_inferno')->toContain('mp_restartgame 1');
});

it('refuses an invalid map and needs map or workshop id', function () {
    $link = cs2Link();
    $tool = new ServerChangeMapTool($this->orga);

    expect((string) $tool->handle(new Request(['server_id' => $link->id, 'map' => 'de_invented'], 'c')))->toContain('Map')
        ->and((string) $tool->handle(new Request(['server_id' => $link->id], 'c')))->not->toBe('')
        ->and(approvalFor($tool, ['server_id' => $link->id]))->toBeNull();
});

it('answers an invented server id with Not found for every server tool', function (string $class) {
    $args = ['server_id' => 99999, 'map' => 'de_dust2', 'action' => 'stop', 'command' => 'status', 'event_id' => 1, 'game_id' => 1, 'preset_key' => 'x'];

    expect((string) (new $class($this->orga))->handle(new Request($args, 'c')))->toStartWith('Not found');
})->with([ServerChangeMapTool::class, ServerPowerTool::class, ServerConsoleTool::class, ServerRestartRoundTool::class, ServerSettingsUpdateTool::class]);
