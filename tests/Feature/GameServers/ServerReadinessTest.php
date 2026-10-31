<?php

use App\Modules\GameServers\Contracts\ServerQuery;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Domain\ServerLimits;
use App\Modules\GameServers\Domain\ServerSpec;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Jobs\CheckServerReadinessJob;
use App\Modules\GameServers\Jobs\PollServerStatusJob;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Testing\FakeServerQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([PollServerStatusJob::class, CheckServerReadinessJob::class]);
    Event::fake([ServerLinkUpdated::class]);
    $this->fake = fakePelican();
});

/**
 * A provisioning link backed by a fake panel server on the first pool allocation.
 *
 * @param  array<string, mixed>  $readiness
 */
function presetLink(object $fake, array $readiness = [], int $installAttempts = 0): ServerLink
{
    $server = $fake->createServer(new ServerSpec('srv', '1', '9', [], new ServerLimits(memory: 1024, disk: 2048), '1', '1'));

    $link = new ServerLink(['preset_key' => 'ttt', 'title' => 'TTT']);
    $link->status = ServerLinkStatus::Provisioning;
    $link->pelican_server_id = $server->id;
    $link->pelican_server_identifier = $server->identifier;
    $link->install_attempts = $installAttempts;
    $link->readiness = array_merge([
        'type' => 'a2s', 'game' => 'Trouble in Terrorist Town', 'folder' => 'garrysmod', 'timeout' => 60,
        'install_marker' => 'garrysmod', 'connect_template' => 'steam://connect/{ip}:{port}', 'install_verified' => false,
    ], $readiness);
    $link->save();

    return $link;
}

function runPoll(ServerLink $link, object $fake, int $attempt = 1): void
{
    (new PollServerStatusJob($link->id, $attempt))->handle($fake);
}

it('verifies the install marker, publishes the join info with the connect template and starts the readiness check', function () {
    $link = presetLink($this->fake);
    $this->fake->setState($link->pelican_server_id, ServerState::Running);
    $this->fake->setFiles($link->pelican_server_identifier, '/', ['garrysmod', 'srcds_run']);

    runPoll($link, $this->fake);

    $link->refresh();
    expect($link->status)->toBe(ServerLinkStatus::Provisioning)
        ->and($link->readiness['install_verified'])->toBeTrue()
        ->and($link->join_info->address)->toBe('192.0.2.10')
        ->and($link->join_info->port)->toBe(27015)
        ->and($link->join_info->connectString)->toBe('steam://connect/192.0.2.10:27015');

    Bus::assertDispatched(CheckServerReadinessJob::class, fn ($job) => $job->serverLinkId === $link->id && $job->attempt === 1);
});

it('flips straight to ready when the preset declares no query', function () {
    $link = presetLink($this->fake, ['type' => null, 'install_marker' => null]);
    $this->fake->setState($link->pelican_server_id, ServerState::Running);

    runPoll($link, $this->fake);

    expect($link->fresh()->status)->toBe(ServerLinkStatus::Ready);
    Bus::assertNotDispatched(CheckServerReadinessJob::class);
    Event::assertDispatched(ServerLinkUpdated::class);
});

it('reinstalls once when the install finished without the marker path', function () {
    $link = presetLink($this->fake);
    $this->fake->setState($link->pelican_server_id, ServerState::Stopped);
    $this->fake->setFiles($link->pelican_server_identifier, '/', ['steamcmd', 'steamapps']);

    runPoll($link, $this->fake);

    $link->refresh();
    expect($this->fake->reinstalled)->toBe([$link->pelican_server_id])
        ->and($link->install_attempts)->toBe(1)
        ->and($link->status)->toBe(ServerLinkStatus::Provisioning);
    Bus::assertDispatched(PollServerStatusJob::class, fn ($job) => $job->serverLinkId === $link->id && $job->attempt === 2);
});

it('marks the install failed when the marker is still missing after the reinstall', function () {
    $link = presetLink($this->fake, installAttempts: 1);
    $this->fake->setState($link->pelican_server_id, ServerState::Stopped);
    $this->fake->setFiles($link->pelican_server_identifier, '/', ['steamcmd']);

    runPoll($link, $this->fake);

    expect($link->fresh()->status)->toBe(ServerLinkStatus::InstallFailed)
        ->and($this->fake->reinstalled)->toBe([]);
});

it('treats a panel-reported install failure of a preset server like a missing marker (reinstall once)', function () {
    $link = presetLink($this->fake);
    $this->fake->setState($link->pelican_server_id, ServerState::Failed);

    runPoll($link, $this->fake);

    expect($this->fake->reinstalled)->toHaveCount(1)->and($link->fresh()->install_attempts)->toBe(1);
});

it('does not decide on the install when the file list cannot be read, it polls again', function () {
    $client = Mockery::mock($this->fake)->makePartial();
    $client->shouldReceive('listFiles')->andThrow(GameServerException::pelicanServerBusy());
    $link = presetLink($this->fake);
    $this->fake->setState($link->pelican_server_id, ServerState::Running);

    runPoll($link, $client);

    $link->refresh();
    expect($link->status)->toBe(ServerLinkStatus::Provisioning)
        ->and($link->install_attempts)->toBe(0);
    Bus::assertDispatched(PollServerStatusJob::class, fn ($job) => $job->attempt === 2);
});

it('marks the link ready with name, map and players once the server answers the query', function () {
    $link = presetLink($this->fake);
    $link->join_info = new JoinInfo('192.0.2.10', 27015, connectString: 'steam://connect/192.0.2.10:27015');
    $link->save();
    $query = new FakeServerQuery(FakeServerQuery::serverInfo('Sommer TTT', 'ttt_minecraft_b5', players: 2));

    (new CheckServerReadinessJob($link->id))->handle($query);

    $link->refresh();
    expect($link->status)->toBe(ServerLinkStatus::Ready)
        ->and($link->query_info['name'])->toBe('Sommer TTT')
        ->and($link->query_info['map'])->toBe('ttt_minecraft_b5')
        ->and($link->query_info['players'])->toBe(2)
        ->and($link->queried_at)->not->toBeNull()
        ->and($query->queries)->toBe([['host' => '192.0.2.10', 'port' => 27015]]);
    Event::assertDispatched(ServerLinkUpdated::class);
});

it('keeps polling while the server is silent and gives up with not responding after the timeout', function () {
    $link = presetLink($this->fake, ['timeout' => 30]);
    $link->join_info = new JoinInfo('192.0.2.10', 27015);
    $link->save();
    $query = new FakeServerQuery(null);

    (new CheckServerReadinessJob($link->id, 1))->handle($query);

    expect($link->fresh()->status)->toBe(ServerLinkStatus::Provisioning);
    Bus::assertDispatched(CheckServerReadinessJob::class, fn ($job) => $job->attempt === 2);

    // 30 s at 5 s intervals: the 6th attempt is the last one.
    (new CheckServerReadinessJob($link->id, 6))->handle($query);

    expect($link->fresh()->status)->toBe(ServerLinkStatus::NotResponding);
});

it('does not accept an answer from the wrong game on that port', function () {
    $link = presetLink($this->fake, ['timeout' => 5]);
    $link->join_info = new JoinInfo('192.0.2.10', 27015);
    $link->save();
    $query = new FakeServerQuery(FakeServerQuery::serverInfo(game: 'Sandbox'));

    (new CheckServerReadinessJob($link->id, 1))->handle($query);

    expect($link->fresh()->status)->toBe(ServerLinkStatus::NotResponding);
});

it('ignores links that are no longer provisioning', function () {
    $link = presetLink($this->fake);
    $link->status = ServerLinkStatus::Stopped;
    $link->save();
    $query = new FakeServerQuery(FakeServerQuery::serverInfo());

    (new CheckServerReadinessJob($link->id))->handle($query);

    expect($query->queries)->toBe([])->and($link->fresh()->status)->toBe(ServerLinkStatus::Stopped);
});

it('refreshes players for ready servers and recovers a not responding one', function () {
    $ready = presetLink($this->fake);
    $ready->join_info = new JoinInfo('192.0.2.10', 27015);
    $ready->status = ServerLinkStatus::Ready;
    $ready->save();
    $silent = presetLink($this->fake);
    $silent->join_info = new JoinInfo('192.0.2.10', 27016);
    $silent->status = ServerLinkStatus::NotResponding;
    $silent->save();

    app()->instance(ServerQuery::class, new FakeServerQuery(FakeServerQuery::serverInfo(players: 7)));

    $this->artisan('gameservers:refresh-queries')->assertExitCode(0);

    expect($ready->fresh()->query_info['players'])->toBe(7)
        ->and($silent->fresh()->status)->toBe(ServerLinkStatus::Ready);
});

it('flips a ready server to not responding only after it was silent for three minutes', function () {
    $link = presetLink($this->fake);
    $link->join_info = new JoinInfo('192.0.2.10', 27015);
    $link->status = ServerLinkStatus::Ready;
    $link->queried_at = now()->subMinute();
    $link->save();
    app()->instance(ServerQuery::class, new FakeServerQuery(null));

    $this->artisan('gameservers:refresh-queries')->assertExitCode(0);
    expect($link->fresh()->status)->toBe(ServerLinkStatus::Ready);

    $link->queried_at = now()->subMinutes(4);
    $link->save();

    $this->artisan('gameservers:refresh-queries')->assertExitCode(0);
    expect($link->fresh()->status)->toBe(ServerLinkStatus::NotResponding);
});
