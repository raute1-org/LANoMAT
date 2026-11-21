<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\GameServers\Actions\ChangeServerMap;
use App\Modules\GameServers\Actions\ReloadMap;
use App\Modules\GameServers\Actions\RestartRound;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Filament\Pages\ServerSettings;
use App\Modules\GameServers\Models\ServerLink;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->pelican = fakePelican();
    $this->pelican->addServer('7', 'abcd1234', ['SRCDS_MAP' => 'de_dust2']);
    $this->link = ServerLink::factory()->permanent(ServerSource::External)->create([
        'title' => 'CS2 5v5',
        'pelican_server_id' => '7',
        'pelican_server_identifier' => 'abcd1234',
        'join_info' => new JoinInfo('203.0.113.5', 27015, null),
        'query_info' => ['name' => 'x', 'map' => 'de_mirage', 'players' => 1, 'max_players' => 10],
    ]);
    $this->orga = User::factory()->orga()->create();
    $this->admin = User::factory()->admin()->create();
});

it('restarts the game with mp_restartgame on a normal server', function () {
    app(RestartRound::class)->handle($this->orga, $this->link);

    expect(array_column($this->pelican->commands, 'command'))->toBe(['mp_restartgame 1']);
});

it('uses gg_restart on a GunGame server', function () {
    $this->link->forceFill(['preset_key' => 'cs2-gungame'])->save();

    app(RestartRound::class)->handle($this->admin, $this->link->fresh());

    expect(array_column($this->pelican->commands, 'command'))->toBe(['gg_restart']);
});

it('refuses a stopped or unlinked server with a clear error and sends nothing', function () {
    $this->pelican->setState('7', ServerState::Stopped);
    expect(fn () => app(RestartRound::class)->handle($this->orga, $this->link))->toThrow(GameServerException::class);

    $unlinked = ServerLink::factory()->permanent()->create(['pelican_server_id' => null, 'pelican_server_identifier' => null]);
    expect(fn () => app(RestartRound::class)->handle($this->orga, $unlinked))->toThrow(GameServerException::class);
    expect(fn () => app(ChangeServerMap::class)->handle($this->orga, $this->link, 'de_dust2', null))->toThrow(GameServerException::class);
    expect($this->pelican->commands)->toBe([]);
});

it('reloads the current standard map and refuses an unknown one', function () {
    app(ReloadMap::class)->handle($this->orga, $this->link);
    expect(array_column($this->pelican->commands, 'command'))->toBe(['changelevel de_mirage']);

    $this->link->forceFill(['query_info' => ['map' => 'workshop/123/foo']])->save();
    expect(fn () => app(ReloadMap::class)->handle($this->orga, $this->link->fresh()))->toThrow(GameServerException::class);
});

it('forbids participants and helpers', function (string $role) {
    $user = $role === 'helper' ? User::factory()->helper()->create() : User::factory()->create();

    expect(fn () => app(RestartRound::class)->handle($user, $this->link))->toThrow(AuthorizationException::class);
    expect(fn () => app(ReloadMap::class)->handle($user, $this->link))->toThrow(AuthorizationException::class);
    expect($this->pelican->commands)->toBe([]);
})->with(['participant', 'helper']);

it('shows the Filament buttons and sends the command', function () {
    $this->actingAs($this->orga);

    Livewire::test(ServerSettings::class, ['server' => $this->link->id])
        ->assertSee(__('gameservers.settings.restart_round.action'))
        ->assertSee(__('gameservers.settings.reload.action'))
        ->call('restartRound');

    expect(array_column($this->pelican->commands, 'command'))->toBe(['mp_restartgame 1']);
});

it('gives canManage and admin props only to those who may manage', function () {
    $this->actingAs(User::factory()->create())->get('/servers')->assertInertia(fn (AssertableInertia $p) => $p
        ->where('servers.0.canManage', false)
        ->missing('servers.0.admin')
        ->where('adminMaps', null));

    $this->actingAs($this->orga)->get('/servers')->assertInertia(fn (AssertableInertia $p) => $p
        ->where('servers.0.canManage', true)
        ->where('servers.0.admin.canReloadMap', true)
        ->where('servers.0.admin.gunGame', false)
        ->has('adminMaps'));
});

it('does not offer management on an unlinked server', function () {
    $this->link->forceFill(['pelican_server_id' => null, 'pelican_server_identifier' => null])->save();

    $this->actingAs($this->admin)->get('/servers')->assertInertia(fn (AssertableInertia $p) => $p
        ->where('servers.0.canManage', false)->where('adminMaps', null));
});

it('runs the web routes for orga and flashes a toast', function () {
    $this->actingAs($this->orga);

    $this->post("/servers/{$this->link->id}/restart-round")->assertRedirect();
    $this->post("/servers/{$this->link->id}/reload-map")->assertRedirect();
    $this->post("/servers/{$this->link->id}/map", ['map' => 'de_inferno'])->assertRedirect();
    $this->post("/servers/{$this->link->id}/map", ['workshop_id' => '3070284539'])->assertRedirect();
    $this->post("/servers/{$this->link->id}/map", ['map' => 'x; quit'])->assertRedirect();

    expect(array_column($this->pelican->commands, 'command'))->toBe([
        'mp_restartgame 1', 'changelevel de_mirage', 'changelevel de_inferno', 'host_workshop_map 3070284539',
    ]);
});

it('denies the web routes to participants and helpers', function (string $role) {
    $this->actingAs($role === 'helper' ? User::factory()->helper()->create() : User::factory()->create());

    $this->post("/servers/{$this->link->id}/restart-round")->assertForbidden();
    $this->post("/servers/{$this->link->id}/reload-map")->assertForbidden();
    $this->post("/servers/{$this->link->id}/map", ['map' => 'de_inferno'])->assertForbidden();
    expect($this->pelican->commands)->toBe([]);
})->with(['participant', 'helper']);

it('denies a non-permanent server link to orga', function () {
    $match = ServerLink::factory()->create(['pelican_server_id' => '7', 'pelican_server_identifier' => 'abcd1234']);

    $this->actingAs($this->orga)->post("/servers/{$match->id}/restart-round")->assertForbidden();
    expect($this->pelican->commands)->toBe([]);
});
