<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\ServerListProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('lets every logged-in user see the permanent servers, whatever their source', function (ServerSource $source) {
    $game = Game::factory()->create(['name' => 'Minecraft']);
    ServerLink::factory()->permanent($source)->create([
        'game_id' => $game->id,
        'title' => 'Survival',
        'description' => 'Whitelist über die Orga',
        'query_info' => ['name' => 'Survival', 'map' => '', 'players' => 3, 'max_players' => 20],
        'queried_at' => now(),
        'join_info' => new JoinInfo(address: '203.0.113.20', port: 25565, connectString: '203.0.113.20:25565'),
    ]);

    $this->actingAs(User::factory()->create())->get('/servers/permanent')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Servers/Permanent')
            ->where('labels.title', 'Dauerserver')
            ->where('servers.0.title', 'Survival')
            ->where('servers.0.game', 'Minecraft')
            ->where('servers.0.address', '203.0.113.20')
            ->where('servers.0.port', 25565)
            ->where('servers.0.status', 'ready')
            ->where('servers.0.query.players', 3)
            ->where('servers.0.connectIsLink', false)
            ->where('servers.0.connectString', '203.0.113.20:25565')
            ->where('servers.0.description', 'Whitelist über die Orga')
        );
})->with([ServerSource::Pelican, ServerSource::Docker, ServerSource::External]);

it('shows offline and silent servers but hides failed and still-pending ones', function () {
    ServerLink::factory()->permanent()->create(['title' => 'A offline', 'status' => ServerLinkStatus::Offline, 'query_info' => ['players' => 5, 'max_players' => 8]]);
    ServerLink::factory()->permanent()->create(['title' => 'B silent', 'status' => ServerLinkStatus::NotResponding]);
    ServerLink::factory()->permanent()->create(['title' => 'C starting', 'status' => ServerLinkStatus::Provisioning]);
    ServerLink::factory()->permanent()->create(['title' => 'D failed', 'status' => ServerLinkStatus::Failed]);
    ServerLink::factory()->permanent()->create(['title' => 'E install', 'status' => ServerLinkStatus::InstallFailed]);

    $this->actingAs(User::factory()->create())->get('/servers/permanent')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('servers', 3)
            ->where('servers.0.title', 'A offline')
            ->where('servers.0.status', 'offline')
            // A switched-off server shows no stale player count.
            ->where('servers.0.query', null)
            ->where('servers.1.status', 'not_responding')
            ->where('servers.2.status', 'provisioning')
        );
});

it('renders an empty list', function () {
    $this->actingAs(User::factory()->create())->get('/servers/permanent')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('servers', 0)->where('labels.empty', 'Es sind noch keine Dauerserver eingerichtet.'));
});

it('does not expose secrets or internals of a server', function () {
    ServerLink::factory()->permanent()->create(['pelican_server_id' => '12', 'pelican_node_id' => '2', 'telemetry_token' => 'tok-secret']);

    $this->actingAs(User::factory()->create())->get('/servers/permanent')
        ->assertOk()
        ->assertDontSee('tok-secret')
        ->assertDontSee('pelican_node_id')
        ->assertDontSee('pelican_server_id');
});

it('redirects guests to login, there is no public page', function () {
    ServerLink::factory()->permanent()->create();

    $this->get('/servers/permanent')->assertRedirect(route('login'));
});

it('has the policy matrix: everyone sees, only admins manage', function (?Role $role, bool $view, bool $manage) {
    $user = $role === null ? null : User::factory()->state(['role' => $role])->create();
    $link = ServerLink::factory()->permanent()->create();

    if ($user === null) {
        expect(true)->toBeTrue();

        return;
    }

    expect($user->can('viewPermanent', ServerLink::class))->toBe($view)
        ->and($user->can('managePermanent', ServerLink::class))->toBe($manage)
        ->and($user->can('power', $link))->toBe($manage)
        ->and($user->can('update', $link))->toBe($manage)
        ->and($user->can('delete', $link))->toBe($manage)
        ->and($user->can('view', $link))->toBe($manage);
})->with([
    'participant' => [Role::Participant, true, false],
    'helper' => [Role::Helper, true, false],
    'orga' => [Role::Orga, true, false],
    'admin' => [Role::Admin, true, true],
]);

it('keeps match and event servers manageable by orga as before', function () {
    $orga = User::factory()->orga()->create();
    $participant = User::factory()->create();
    $link = ServerLink::factory()->create();

    expect($orga->can('power', $link))->toBeTrue()
        ->and($orga->can('delete', $link))->toBeTrue()
        ->and($participant->can('view', $link))->toBeFalse()
        ->and($participant->can('power', $link))->toBeFalse();
});

it('shows the UT2004 players as 2/16 and the who-is-on list to logged-in users only', function () {
    ServerLink::factory()->permanent(ServerSource::External)->create([
        'title' => 'UT2004 VRM',
        'query_info' => [
            'name' => 'Raute1 LAN', 'map' => 'DM-DE-GRENDELKEEP', 'players' => 2, 'max_players' => 16,
            'player_list' => [
                ['name' => 'Kleintonno', 'score' => 2, 'seconds' => null],
                ['name' => 'klaus', 'score' => 17, 'seconds' => null],
            ],
        ],
        'queried_at' => now(),
        'join_info' => new JoinInfo(address: '203.0.113.20', port: 7777),
    ]);

    $this->actingAs(User::factory()->create())->get('/servers/permanent')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('servers.0.query.players', 2)
            ->where('servers.0.query.maxPlayers', 16)
            ->where('servers.0.query.playerList.0.name', 'klaus')
            ->where('servers.0.query.playerList.1.name', 'Kleintonno')
        );

    auth()->logout();
    $this->get('/servers/permanent')->assertRedirect(route('login'));
});

it('keeps the player list out of every projection that is not an authenticated page', function () {
    $link = ServerLink::factory()->permanent(ServerSource::External)->create([
        'query_info' => ['players' => 1, 'max_players' => 8, 'player_list' => [['name' => 'geheim', 'score' => 1, 'seconds' => 1]]],
        'queried_at' => now(),
    ]);

    expect(json_encode(ServerListProjection::permanentDto($link)))->not->toContain('geheim')
        ->and(json_encode(ServerListProjection::forPermanent()))->not->toContain('geheim')
        ->and(json_encode(ServerListProjection::permanentDto($link, withPlayerList: true)))->toContain('geheim');
});

it('lists the Minecraft status sample names without scores', function () {
    $link = ServerLink::factory()->permanent(ServerSource::External)->create([
        'query_info' => ['players' => 1, 'max_players' => 8, 'player_names' => ['Steve']],
        'queried_at' => now(),
    ]);

    expect(ServerListProjection::permanentDto($link, withPlayerList: true)['query']['playerList'])
        ->toBe([['name' => 'Steve', 'score' => null, 'seconds' => null]]);
});
