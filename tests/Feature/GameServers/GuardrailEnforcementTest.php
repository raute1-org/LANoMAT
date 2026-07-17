<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Actions\SetManualJoinInfo;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Jobs\PollServerStatusJob;
use App\Modules\GameServers\Jobs\ProvisionMatchServerJob;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

function guardrailBreachingMatch(): GameMatch
{
    $game = Game::factory()->create([
        'pelican_egg_id' => 'egg-42',
        // 256 base + 200 * 64 = 13056 MB, far over any sane cap.
        'default_server_config' => new ServerConfig(maxPlayers: 200, map: 'de_dust2'),
    ]);
    $tournament = Tournament::factory()->create(['game_id' => $game->id]);

    return GameMatch::factory()->for($tournament)->create();
}

it('refuses to provision and marks the ServerLink Failed when the RAM guardrail is exceeded', function () {
    Config::set('services.pelican.max_ram_mb', 2048);
    Bus::fake([PollServerStatusJob::class]);
    $fake = fakePelican();

    $match = guardrailBreachingMatch();

    try {
        (new ProvisionMatchServerJob($match->id))->handle($fake);
        test()->fail('Expected GameServerException to be thrown.');
    } catch (GameServerException $e) {
        // i18n gate: the exception the operator/admin ultimately sees
        // carries a real German label, not just an internal message.
        expect(__($e->translationKey))->toBe('Der geschätzte Arbeitsspeicherbedarf überschreitet das Limit pro Server.');
    }

    // No server was ever created on the fake Pelican client — the guardrail
    // must be enforced BEFORE createServer, not just displayed in the UI.
    $fake->assertNothingCreated();

    $match->refresh();
    expect($match->server_link_id)->not->toBeNull();

    $link = $match->serverLink;
    expect($link->status)->toBe(ServerLinkStatus::Failed);
});

it('refuses to provision on the automatic path (requester=null) when the global running-server cap is reached', function () {
    Config::set('services.pelican.max_ram_mb', 1_000_000);
    Config::set('services.pelican.max_slots', 1_000);
    Config::set('services.pelican.max_running_servers', 2);
    Bus::fake([PollServerStatusJob::class]);
    $fake = fakePelican();

    // Two already-running links saturate the node-wide cap of 2 — no human
    // requester is involved anywhere in this test, proving the global cap
    // (not the per-user cap) is what stops the automatic path.
    ServerLink::factory()->count(2)->create(['status' => ServerLinkStatus::Ready]);

    $game = Game::factory()->create([
        'pelican_egg_id' => 'egg-42',
        'default_server_config' => new ServerConfig(maxPlayers: 10, map: 'de_dust2'),
    ]);
    $tournament = Tournament::factory()->create(['game_id' => $game->id]);
    $match = GameMatch::factory()->for($tournament)->create();

    try {
        (new ProvisionMatchServerJob($match->id))->handle($fake);
        test()->fail('Expected GameServerException to be thrown.');
    } catch (GameServerException $e) {
        expect(__($e->translationKey))->toBe('Es laufen bereits die maximal zulässige Anzahl an Spielservern auf diesem Knoten.');
    }

    // The auto path now has teeth: no server was created, and the ServerLink
    // this job claimed for itself is left Failed, not dangling in
    // Provisioning.
    $fake->assertNothingCreated();

    $match->refresh();
    expect($match->server_link_id)->not->toBeNull();
    expect($match->serverLink->status)->toBe(ServerLinkStatus::Failed);
});

it('lets the automatic path through when it is the only server and the global cap allows exactly one', function () {
    Config::set('services.pelican.max_ram_mb', 1_000_000);
    Config::set('services.pelican.max_slots', 1_000);
    Config::set('services.pelican.max_running_servers', 1);
    Bus::fake([PollServerStatusJob::class]);
    $fake = fakePelican();

    // No other running links exist — the job's own just-claimed link is
    // excluded from the count (see GuardrailPolicy's docblock), so this must
    // succeed even though the cap is as tight as 1.
    $game = Game::factory()->create([
        'pelican_egg_id' => 'egg-42',
        'default_server_config' => new ServerConfig(maxPlayers: 10, map: 'de_dust2'),
    ]);
    $tournament = Tournament::factory()->create(['game_id' => $game->id]);
    $match = GameMatch::factory()->for($tournament)->create();

    (new ProvisionMatchServerJob($match->id))->handle($fake);

    $fake->assertServerCreated('egg-42');

    $match->refresh();
    expect($match->serverLink->status)->toBe(ServerLinkStatus::Provisioning);
});

it('provisions normally when the config is within the guardrail limits', function () {
    Config::set('services.pelican.max_ram_mb', 1_000_000);
    Config::set('services.pelican.max_slots', 1_000);
    Bus::fake([PollServerStatusJob::class]);
    $fake = fakePelican();

    $game = Game::factory()->create([
        'pelican_egg_id' => 'egg-42',
        'default_server_config' => new ServerConfig(maxPlayers: 10, map: 'de_dust2'),
    ]);
    $tournament = Tournament::factory()->create(['game_id' => $game->id]);
    $match = GameMatch::factory()->for($tournament)->create();

    (new ProvisionMatchServerJob($match->id))->handle($fake);

    $fake->assertServerCreated('egg-42');

    $match->refresh();
    $link = $match->serverLink;
    expect($link->status)->toBe(ServerLinkStatus::Provisioning);
});

it('refuses SetManualJoinInfo when the helper already has max_servers_per_user running servers', function () {
    Config::set('services.pelican.max_servers_per_user', 1);

    $helper = User::factory()->create(['role' => Role::Helper]);

    ServerLink::factory()->create([
        'requested_by' => $helper->id,
        'status' => ServerLinkStatus::Ready,
    ]);

    $match = GameMatch::factory()->create();
    $info = new JoinInfo(address: '203.0.113.5', port: 27015);

    expect(fn () => (new SetManualJoinInfo)->handle($match, $info, $helper))
        ->toThrow(GameServerException::class);

    expect($match->fresh()->server_link_id)->toBeNull();
});

it('lets SetManualJoinInfo through and attributes the ServerLink to the acting helper when within limits', function () {
    Config::set('services.pelican.max_servers_per_user', 2);

    $helper = User::factory()->create(['role' => Role::Helper]);
    $match = GameMatch::factory()->create();
    $info = new JoinInfo(address: '203.0.113.5', port: 27015);

    $link = (new SetManualJoinInfo)->handle($match, $info, $helper);

    expect($link->requested_by)->toBe($helper->id);
});
