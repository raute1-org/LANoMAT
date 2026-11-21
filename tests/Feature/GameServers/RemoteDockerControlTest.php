<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\GameServers\Actions\ControlRemoteDockerPower;
use App\Modules\GameServers\Actions\LinkRemoteDockerServer;
use App\Modules\GameServers\Actions\PowerPermanentServer;
use App\Modules\GameServers\Actions\ReadRemoteLogs;
use App\Modules\GameServers\Actions\SendRemoteConsoleCommand;
use App\Modules\GameServers\Contracts\RemoteDockerControl;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ConsoleKind;
use App\Modules\GameServers\Enums\ControlDriver;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Filament\Pages\ServerSettings;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\RemoteDockerGuard;
use App\Modules\GameServers\Support\SshRemoteDockerControl;
use App\Modules\GameServers\Testing\FakeRemoteDockerControl;
use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Exceptions\RemoteExecutionException;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->host = RemoteHost::factory()->create(['host_fingerprint' => 'SHA256:placeholder']);
    $this->link = ServerLink::factory()->remoteDocker($this->host, 'game-container')->create(['title' => 'Test server']);
    $this->orga = User::factory()->orga()->create();
    $this->admin = User::factory()->admin()->create();
});

function sshControl(): array
{
    $executor = fakeRemote();

    return [app(SshRemoteDockerControl::class), $executor];
}

// ---------------------------------------------------------------- driver derivation

it('derives the control driver without changing existing behaviour', function () {
    $pelican = ServerLink::factory()->permanent(ServerSource::Pelican)->create();
    $adopted = ServerLink::factory()->permanent()->create(['pelican_server_id' => '7', 'pelican_server_identifier' => 'abcd1234']);
    $docker = ServerLink::factory()->permanent(ServerSource::Docker)->create();
    $plain = ServerLink::factory()->permanent()->create();
    $both = ServerLink::factory()->remoteDocker($this->host)->create(['pelican_server_id' => '7', 'pelican_server_identifier' => 'abcd1234']);

    expect($pelican->controlDriver())->toBe(ControlDriver::Pelican)
        ->and($adopted->controlDriver())->toBe(ControlDriver::Pelican)
        ->and($docker->controlDriver())->toBe(ControlDriver::Docker)
        ->and($plain->controlDriver())->toBe(ControlDriver::None)
        ->and($plain->canBeControlled())->toBeFalse()
        ->and($this->link->controlDriver())->toBe(ControlDriver::RemoteDocker)
        ->and($this->link->canBeControlled())->toBeTrue()
        ->and($both->controlDriver())->toBe(ControlDriver::Pelican);
});

it('falls back to none when the host was deleted', function () {
    $this->host->delete();

    $link = $this->link->fresh();

    expect($link->remote_host_id)->toBeNull()
        ->and($link->controlDriver())->toBe(ControlDriver::None);
});

// ---------------------------------------------------------------- ssh control: exact commands

it('builds the exact power, status, logs and console command lines', function () {
    [$control, $executor] = sshControl();
    $executor->queueResult('docker inspect', new CommandResult(0, "running\n", ''));

    $control->power($this->host, 'game-container', PowerAction::Start);
    $control->power($this->host, 'game-container', PowerAction::Stop);
    $control->power($this->host, 'game-container', PowerAction::Restart);
    expect($control->status($this->host, 'game-container'))->toBe('running');
    $control->logs($this->host, 'game-container', 50);
    $control->command($this->host, 'game-container', ConsoleKind::RconCli, 'whitelist add Steve');

    expect(array_column($executor->commands, 'command'))->toBe([
        "docker start 'game-container'",
        "docker stop 'game-container'",
        "docker restart 'game-container'",
        "docker inspect -f '{{.State.Status}}' 'game-container'",
        "docker logs --tail 50 'game-container'",
        "docker exec 'game-container' rcon-cli 'whitelist add Steve'",
    ]);
    // Power gets a long budget (stop saves the world), the rest a short one.
    expect($executor->timeouts)->toBe([120, 120, 120, 15, 15, 15]);
});

it('never offers kill', function () {
    [$control, $executor] = sshControl();

    expect(fn () => $control->power($this->host, 'game-container', PowerAction::Kill))->toThrow(GameServerException::class);
    $executor->assertNothingRan();
});

it('passes a console command as exactly one escaped argument', function (string $payload) {
    [$control, $executor] = sshControl();

    $control->command($this->host, 'game-container', ConsoleKind::RconCli, $payload);

    $sent = $executor->commands[0]['command'];
    expect($sent)->toBe('docker exec \'game-container\' rcon-cli '.escapeshellarg($payload));
    // Whatever the payload holds, a shell reads the whole tail as a single word.
    $words = [];
    preg_match_all("/'(?:[^']|'\\\\'')*'|[^\\s']+/", $sent, $words);
    expect(count($words[0]))->toBe(5);
})->with([
    'semicolon' => 'list; rm -rf /',
    'and' => 'list && reboot',
    'command substitution' => 'say $(reboot)',
    'backticks' => 'say `reboot`',
    'single quote' => "say it's me",
    'double quote' => 'say "hi"',
    'pipe and redirect' => 'list | tee /etc/passwd > /dev/null',
]);

it('lets a real shell see the payload as one literal word', function () {
    foreach (['list; echo PWNED', 'say $(echo PWNED)', 'say `echo PWNED`', "say it's \"x\" && echo PWNED"] as $payload) {
        $script = 'for a in "$@"; do printf "[%s]" "$a"; done';
        $out = (string) shell_exec('sh -c '.escapeshellarg($script).' _ rcon-cli '.escapeshellarg(RemoteDockerGuard::command($payload)));

        expect($out)->toBe("[rcon-cli][{$payload}]");
    }
});

it('refuses unsafe console input before anything runs', function (string $payload) {
    [$control, $executor] = sshControl();

    expect(fn () => $control->command($this->host, 'game-container', ConsoleKind::RconCli, $payload))
        ->toThrow(GameServerException::class);
    $executor->assertNothingRan();
})->with([
    'newline' => "list\nreboot",
    'carriage return' => "list\rreboot",
    'trailing newline' => "list\n",
    'nul' => "list\0",
    'escape' => "list\e[31m",
    'tab' => "li\tst",
    'empty' => '',
    'blank' => '   ',
    'too long' => str_repeat('a', 257),
    'flag' => '--help',
    'flag with space' => '-p secret list',
    'non ascii' => 'say Grüße',
    'invalid utf8' => "say \xC3\x28",
]);

it('accepts a command of exactly 256 characters', function () {
    expect(RemoteDockerGuard::command(str_repeat('a', 256)))->toHaveLength(256);
});

it('refuses invalid container names', function (string $name) {
    [$control, $executor] = sshControl();

    expect(fn () => $control->power($this->host, $name, PowerAction::Start))->toThrow(GameServerException::class);
    expect(fn () => $control->logs($this->host, $name, 10))->toThrow(GameServerException::class);
    expect(fn () => $control->command($this->host, $name, ConsoleKind::RconCli, 'list'))->toThrow(GameServerException::class);
    expect($control->status($this->host, $name))->toBeNull();
    $executor->assertNothingRan();
})->with([
    'semicolon' => 'mc; reboot',
    'substitution' => 'mc$(reboot)',
    'backtick' => 'mc`id`',
    'quote' => "mc'",
    'space' => 'mc 1',
    'leading dash' => '-rf',
    'leading dot' => '.hidden',
    'trailing newline' => "mc\n",
    'empty' => '',
    'too long' => 'a'.str_repeat('b', 128),
    'slash' => '../etc',
]);

it('accepts a valid container name', function () {
    expect(RemoteDockerGuard::container('minecraft2-mc-1'))->toBe('minecraft2-mc-1')
        ->and(RemoteDockerGuard::container(str_repeat('a', 128)))->toHaveLength(128);
});

it('limits the log lines to 1 to 500', function (int $lines) {
    [$control, $executor] = sshControl();

    expect(fn () => $control->logs($this->host, 'game-container', $lines))->toThrow(GameServerException::class);
    $executor->assertNothingRan();
})->with([0, -1, 501, 100000]);

it('reads the log tail command for 1 and 500 lines', function () {
    [$control, $executor] = sshControl();

    $control->logs($this->host, 'game-container', 1);
    $control->logs($this->host, 'game-container', 500);

    expect(array_column($executor->commands, 'command'))->toBe([
        "docker logs --tail 1 'game-container'",
        "docker logs --tail 500 'game-container'",
    ]);
});

it('never dumps the container environment', function () {
    [$control, $executor] = sshControl();
    $executor->queueResult('docker inspect', new CommandResult(0, "running\n", ''));

    $control->status($this->host, 'game-container');
    $control->exists($this->host, 'game-container');

    foreach ($executor->commands as $c) {
        expect($c['command'])->toContain("-f '{{.State.Status}}'")->not->toContain('.Config');
    }
});

// ---------------------------------------------------------------- output hygiene

it('strips escapes and control characters, keeps the tail and valid utf-8', function () {
    expect(RemoteDockerGuard::sanitizeOutput("\e[31mred\e[0m \e]0;title\x07ok\r\n§aGreen\x00\x07x"))->toBe('red ok'."\n".'Greenx');

    $long = str_repeat("ä\n", 60000);
    $out = RemoteDockerGuard::sanitizeOutput($long);

    expect(strlen($out))->toBeLessThanOrEqual(RemoteDockerGuard::MAX_OUTPUT_BYTES)
        ->and(mb_check_encoding($out, 'UTF-8'))->toBeTrue()
        ->and(json_encode($out))->not->toBeFalse();

    $tail = RemoteDockerGuard::sanitizeOutput(str_repeat('a', 70000).'THE-END');
    expect($tail)->toEndWith('THE-END');

    expect(mb_check_encoding(RemoteDockerGuard::sanitizeOutput("bad \xC3\x28 bytes"), 'UTF-8'))->toBeTrue();
});

it('merges both streams of the logs and sanitizes them', function () {
    [$control, $executor] = sshControl();
    $executor->queueResult('docker logs', new CommandResult(0, "out\n", "\e[1merr\e[0m\n"));

    expect($control->logs($this->host, 'game-container', 10))->toBe("out\nerr");
});

// ---------------------------------------------------------------- failures

it('maps host and command failures to domain errors without leaking remote output', function () {
    [$control, $executor] = sshControl();

    $executor->queueResult('docker exec', new CommandResult(1, '', 'Error response from daemon: container x is not running'));
    expect(fn () => $control->command($this->host, 'game-container', ConsoleKind::RconCli, 'list'))
        ->toThrow(fn (GameServerException $e) => expect($e->translationKey)->toBe('gameservers.remote.errors.not_running'));

    $executor->queueResult('docker logs', new CommandResult(1, '', 'secret-token=abc'));
    try {
        $control->logs($this->host, 'game-container', 5);
    } catch (GameServerException $e) {
        expect($e->getMessage())->not->toContain('secret')->and($e->translationKey)->toBe('gameservers.remote.errors.command_failed');
    }

    $executor->failWithOnRun = RemoteExecutionException::commandFailed();
    expect(fn () => $control->logs($this->host, 'game-container', 5))
        ->toThrow(fn (GameServerException $e) => expect($e->translationKey)->toBe('gameservers.remote.errors.unavailable'));
    expect($control->status($this->host, 'game-container'))->toBeNull();
});

it('knows a missing container', function () {
    [$control, $executor] = sshControl();
    $executor->queueResult('docker inspect', new CommandResult(1, '', 'Error: No such object: x'));

    expect($control->exists($this->host, 'game-container'))->toBeFalse();
});

// ---------------------------------------------------------------- actions and policy

beforeEach(function () {
    $this->fake = new FakeRemoteDockerControl;
    app()->instance(RemoteDockerControl::class, $this->fake);
});

it('powers the container as orga and admin, marks a stop offline and audits', function () {
    Log::spy();

    $state = app(ControlRemoteDockerPower::class)->handle($this->orga, $this->link, PowerAction::Stop);

    expect($state)->toBe('exited')
        ->and($this->fake->powered)->toHaveCount(1)
        ->and($this->fake->powered[0]['action'])->toBe('stop')
        ->and($this->link->fresh()->status)->toBe(ServerLinkStatus::Offline);
    Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $c) => $m === 'remote_docker.power' && $c['user_id'] === $this->orga->id && $c['server_id'] === $this->link->id)->once();

    expect(app(ControlRemoteDockerPower::class)->handle($this->admin, $this->link, PowerAction::Start))->toBe('running');
});

it('sends a command, returns the answer and audits only a shortened command', function () {
    Log::spy();
    $long = 'say '.str_repeat('x', 200);

    $answer = app(SendRemoteConsoleCommand::class)->handle($this->orga, $this->link, $long);

    expect($answer)->toBe($this->fake->commandOutput)
        ->and($this->fake->commands[0]['console'])->toBe('rcon-cli');
    Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $c) => $m === 'remote_docker.command' && mb_strlen($c['command']) === 80 && ! array_key_exists('output', $c))->once();
});

it('reads logs without logging their text', function () {
    Log::spy();

    expect(app(ReadRemoteLogs::class)->handle($this->orga, $this->link, 20))->toBe($this->fake->logOutput)
        ->and($this->fake->logReads[0]['lines'])->toBe(20);
    Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $c) => $m === 'remote_docker.logs' && ! array_key_exists('output', $c))->once();
});

it('rejects bad console input at the action and sends nothing', function () {
    expect(fn () => app(SendRemoteConsoleCommand::class)->handle($this->orga, $this->link, "list; rm\nreboot"))->toThrow(GameServerException::class);
    expect(fn () => app(ReadRemoteLogs::class)->handle($this->orga, $this->link, 9999))->toThrow(GameServerException::class);
    expect($this->fake->commands)->toBe([])->and($this->fake->logReads)->toBe([]);
});

it('forbids participants and helpers', function (string $role) {
    $user = $role === 'helper' ? User::factory()->helper()->create() : User::factory()->create();

    expect(fn () => app(ControlRemoteDockerPower::class)->handle($user, $this->link, PowerAction::Restart))->toThrow(AuthorizationException::class);
    expect(fn () => app(SendRemoteConsoleCommand::class)->handle($user, $this->link, 'list'))->toThrow(AuthorizationException::class);
    expect(fn () => app(ReadRemoteLogs::class)->handle($user, $this->link, 10))->toThrow(AuthorizationException::class);
    expect($this->fake->powered)->toBe([])->and($this->fake->commands)->toBe([])->and($this->fake->logReads)->toBe([]);
})->with(['participant', 'helper']);

it('says clearly that an unlinked server cannot be controlled', function () {
    $plain = ServerLink::factory()->permanent()->create();

    foreach ([
        fn () => app(ControlRemoteDockerPower::class)->handle($this->admin, $plain, PowerAction::Start),
        fn () => app(SendRemoteConsoleCommand::class)->handle($this->admin, $plain, 'list'),
        fn () => app(ReadRemoteLogs::class)->handle($this->admin, $plain, 10),
    ] as $call) {
        expect($call)->toThrow(fn (GameServerException $e) => expect($e->translationKey)->toBe('gameservers.remote.errors.not_linked'));
    }
});

it('powers a remote server through the admin resource action too', function () {
    app(PowerPermanentServer::class)->handle($this->admin, $this->link, PowerAction::Restart);

    expect($this->fake->powered[0]['action'])->toBe('restart');
});

it('does not touch the Pelican path for a Pelican server', function () {
    $pelican = fakePelican();
    $pelican->addServer('7', 'abcd1234');
    $adopted = ServerLink::factory()->permanent()->create(['pelican_server_id' => '7', 'pelican_server_identifier' => 'abcd1234']);

    app(PowerPermanentServer::class)->handle($this->admin, $adopted, PowerAction::Restart);

    expect($this->fake->powered)->toBe([]);
});

// ---------------------------------------------------------------- linking

it('links an external server to a host and container', function () {
    $plain = ServerLink::factory()->permanent()->create();

    app(LinkRemoteDockerServer::class)->handle($plain, $this->host, 'game-container');

    $plain->refresh();
    expect($plain->controlDriver())->toBe(ControlDriver::RemoteDocker)
        ->and($plain->remote_host_id)->toBe($this->host->id)
        ->and($plain->console_kind)->toBe(ConsoleKind::RconCli)
        ->and($plain->source)->toBe(ServerSource::External);
});

it('refuses to link a missing container, an unpinned host, a Pelican-linked or non-external server', function () {
    $plain = ServerLink::factory()->permanent()->create();
    $action = app(LinkRemoteDockerServer::class);

    $this->fake->containerExists = false;
    expect(fn () => $action->handle($plain, $this->host, 'nope'))->toThrow(GameServerException::class);

    $this->fake->containerExists = true;
    $unpinned = RemoteHost::factory()->create(['host_fingerprint' => null]);
    expect(fn () => $action->handle($plain, $unpinned, 'game-container'))->toThrow(GameServerException::class);

    expect(fn () => $action->handle($plain, $this->host, 'bad name; reboot'))->toThrow(GameServerException::class);

    $adopted = ServerLink::factory()->permanent()->create(['pelican_server_id' => '7', 'pelican_server_identifier' => 'abcd1234']);
    expect(fn () => $action->handle($adopted, $this->host, 'game-container'))->toThrow(GameServerException::class);

    $docker = ServerLink::factory()->permanent(ServerSource::Docker)->create();
    expect(fn () => $action->handle($docker, $this->host, 'game-container'))->toThrow(GameServerException::class);

    $match = ServerLink::factory()->create();
    expect(fn () => $action->handle($match, $this->host, 'game-container'))->toThrow(GameServerException::class);

    expect($plain->fresh()->remote_host_id)->toBeNull();
});

it('links through the artisan command and prints no secrets', function () {
    $plain = ServerLink::factory()->permanent()->create(['title' => 'Minecraft Test']);

    $this->artisan('gameservers:link-remote-docker', ['server' => 'Minecraft Test', '--host' => $this->host->name, '--container' => 'game-container', '--console' => 'rcon-cli'])
        ->expectsOutputToContain('Linked server')
        ->assertSuccessful();

    expect($plain->fresh()->remote_container)->toBe('game-container');
});

it('fails the artisan command cleanly on bad input', function () {
    ServerLink::factory()->permanent()->create(['title' => 'Minecraft Test']);

    $this->artisan('gameservers:link-remote-docker', ['server' => 'Unknown', '--host' => $this->host->name, '--container' => 'x'])->assertFailed();
    $this->artisan('gameservers:link-remote-docker', ['server' => 'Minecraft Test', '--host' => 'nope', '--container' => 'x'])->assertFailed();
    $this->artisan('gameservers:link-remote-docker', ['server' => 'Minecraft Test', '--host' => $this->host->name])->assertFailed();
    $this->artisan('gameservers:link-remote-docker', ['server' => 'Minecraft Test', '--host' => $this->host->name, '--container' => 'x y'])->assertFailed();
    $this->artisan('gameservers:link-remote-docker', ['server' => 'Minecraft Test', '--host' => $this->host->name, '--container' => 'x', '--console' => 'bash'])->assertFailed();
    $this->fake->containerExists = false;
    $this->artisan('gameservers:link-remote-docker', ['server' => 'Minecraft Test', '--host' => $this->host->name, '--container' => 'x'])->assertFailed();
});

// ---------------------------------------------------------------- web routes

it('serves power, command and logs to orga and admin only', function () {
    $this->actingAs($this->orga);

    $this->post("/servers/{$this->link->id}/remote/power", ['action' => 'restart'])->assertRedirect();
    expect($this->fake->powered[0]['action'])->toBe('restart');

    $this->postJson("/servers/{$this->link->id}/remote/command", ['command' => 'list'])
        ->assertOk()->assertJson(['output' => $this->fake->commandOutput]);

    $this->getJson("/servers/{$this->link->id}/remote/logs?lines=20")
        ->assertOk()->assertJson(['output' => $this->fake->logOutput]);
    expect($this->fake->logReads[0]['lines'])->toBe(20);

    foreach (['participant' => User::factory()->create(), 'helper' => User::factory()->helper()->create()] as $user) {
        $this->actingAs($user);
        $this->post("/servers/{$this->link->id}/remote/power", ['action' => 'stop'])->assertForbidden();
        // 403 before validation, even for an invalid body.
        $this->postJson("/servers/{$this->link->id}/remote/command", [])->assertForbidden();
        $this->getJson("/servers/{$this->link->id}/remote/logs?lines=0")->assertForbidden();
    }

    expect($this->fake->powered)->toHaveCount(1)->and($this->fake->commands)->toHaveCount(1);
});

it('answers invalid input and failures with a message, never with remote output', function () {
    $this->actingAs($this->orga);

    $this->postJson("/servers/{$this->link->id}/remote/command", ['command' => "list\nreboot"])->assertStatus(422);
    $this->postJson("/servers/{$this->link->id}/remote/command", ['command' => str_repeat('a', 300)])->assertStatus(422);
    $this->postJson("/servers/{$this->link->id}/remote/command", ['command' => '--help'])
        ->assertStatus(422)->assertJson(['message' => __('gameservers.remote.errors.invalid_command')]);
    $this->getJson("/servers/{$this->link->id}/remote/logs?lines=501")->assertStatus(422);
    $this->post("/servers/{$this->link->id}/remote/power", ['action' => 'kill'])->assertSessionHasErrors('action');

    $this->fake->failWith = GameServerException::remoteUnavailable();
    $this->postJson("/servers/{$this->link->id}/remote/command", ['command' => 'list'])
        ->assertStatus(422)->assertJson(['message' => __('gameservers.remote.errors.unavailable')]);

    expect($this->fake->commands)->toBe([]);
});

it('refuses a server that is not linked to a container', function () {
    $plain = ServerLink::factory()->permanent()->create();
    $this->actingAs($this->admin);

    $this->postJson("/servers/{$plain->id}/remote/command", ['command' => 'list'])
        ->assertStatus(422)->assertJson(['message' => __('gameservers.remote.errors.not_linked')]);
});

it('throttles the console to 30 requests per minute and user', function () {
    $this->actingAs($this->orga);

    for ($i = 0; $i < 30; $i++) {
        $this->postJson("/servers/{$this->link->id}/remote/command", ['command' => 'list'])->assertOk();
    }

    $this->postJson("/servers/{$this->link->id}/remote/command", ['command' => 'list'])->assertStatus(429);
});

it('hides the controls from guests', function () {
    $this->postJson("/servers/{$this->link->id}/remote/command", ['command' => 'list'])->assertUnauthorized();
});

// ---------------------------------------------------------------- page props

it('sends canManage and the remote block only to orga and admin', function () {
    $props = fn (User $user) => collect(
        $this->actingAs($user)->get('/servers')->assertOk()->viewData('page')['props']['permanent'],
    )->firstWhere('id', $this->link->id);

    $orga = $props($this->orga);
    expect($orga['canManage'])->toBeTrue()
        ->and($orga['admin']['remote']['quickCommands'])->toContain('list', 'whitelist add ')
        ->and($orga['admin']['remote']['labels'])->toHaveKey('heading');

    foreach ([User::factory()->create(), User::factory()->helper()->create()] as $user) {
        $dto = $props($user);
        expect($dto['canManage'])->toBeFalse()->and($dto)->not->toHaveKey('admin');
        // The container and host never reach a participant.
        expect(json_encode($dto))->not->toContain('game-container');
    }
});

it('keeps the Pelican admin block unchanged for Pelican servers', function () {
    fakePelican()->addServer('7', 'abcd1234');
    $adopted = ServerLink::factory()->permanent()->create(['title' => 'Adopted', 'pelican_server_id' => '7', 'pelican_server_identifier' => 'abcd1234']);

    $dto = collect($this->actingAs($this->orga)->get('/servers')->viewData('page')['props']['permanent'])->firstWhere('id', $adopted->id);

    expect($dto['canManage'])->toBeTrue()->and($dto['admin'])->not->toHaveKey('remote');
});

// ---------------------------------------------------------------- Filament settings page

it('shows the control section with status and runs power, console and logs on the settings page', function () {
    $this->actingAs($this->orga);
    $this->fake->state = 'running';

    Livewire::test(ServerSettings::class, ['server' => $this->link->id])
        ->assertSee(__('gameservers.remote.heading'))
        ->assertSee(__('gameservers.remote.state.running'))
        ->call('remotePower', 'restart')
        ->set('consoleCommand', 'list')
        ->call('sendConsole')
        ->assertSee($this->fake->commandOutput)
        ->assertSet('consoleHistory', ['list'])
        ->assertSet('consoleCommand', '')
        ->set('logLines', 25)
        ->call('loadLogs')
        ->assertSee('[Server] Done');

    expect($this->fake->powered[0]['action'])->toBe('restart')
        ->and($this->fake->logReads[0]['lines'])->toBe(25);
});

it('caps the console history and shows an error for an invalid command', function () {
    $this->actingAs($this->orga);

    $page = Livewire::test(ServerSettings::class, ['server' => $this->link->id]);

    foreach (range(1, 12) as $i) {
        $page->set('consoleCommand', "say {$i}")->call('sendConsole');
    }

    $page->assertSet('consoleHistory', array_map(fn (int $i) => "say {$i}", range(12, 3)));

    $count = count($this->fake->commands);
    $page->set('consoleCommand', "list\nreboot")->call('sendConsole');
    expect($this->fake->commands)->toHaveCount($count);
});

it('offers the link action to admins for an unlinked external server only', function () {
    $plain = ServerLink::factory()->permanent()->create(['title' => 'Unlinked']);

    $this->actingAs($this->admin);
    Livewire::test(ServerSettings::class, ['server' => $plain->id])->assertActionVisible('linkRemote')
        ->callAction('linkRemote', ['host' => $this->host->id, 'container' => 'game-container']);
    expect($plain->fresh()->controlDriver())->toBe(ControlDriver::RemoteDocker);

    Livewire::test(ServerSettings::class, ['server' => $plain->id])->assertActionHidden('linkRemote');

    $this->actingAs($this->orga);
    $other = ServerLink::factory()->permanent()->create(['title' => 'Other']);
    Livewire::test(ServerSettings::class, ['server' => $other->id])->assertActionHidden('linkRemote');
});
