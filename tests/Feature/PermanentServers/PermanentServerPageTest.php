<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Models\ServerLink;
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

    $this->actingAs(User::factory()->create())->get('/servers')
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

    $this->actingAs(User::factory()->create())->get('/servers')
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
    $this->actingAs(User::factory()->create())->get('/servers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('servers', 0)->where('labels.empty', 'Es sind noch keine Dauerserver eingerichtet.'));
});

it('does not expose secrets or internals of a server', function () {
    ServerLink::factory()->permanent()->create(['pelican_server_id' => '12', 'pelican_node_id' => '2', 'telemetry_token' => 'tok-secret']);

    $this->actingAs(User::factory()->create())->get('/servers')
        ->assertOk()
        ->assertDontSee('tok-secret')
        ->assertDontSee('pelican_node_id')
        ->assertDontSee('pelican_server_id');
});

it('redirects guests to login, there is no public page', function () {
    ServerLink::factory()->permanent()->create();

    $this->get('/servers')->assertRedirect(route('login'));
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
