<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\ServerListProjection;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('renders the event servers on the overview with german labels and a ready server\'s mono address', function () {
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    $match = GameMatch::factory()->for($tournament)->create();

    ServerLink::factory()->create([
        'match_id' => $match->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.5', port: 27015, connectString: 'steam://connect/203.0.113.5:27015'),
    ]);

    $this->actingAs(User::factory()->create())->get("/servers?event={$event->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Servers/Index')
            ->where('labels.title', 'Spielserver')
            ->where('overviewLabels.title', 'Server')
            ->where('event.isCurrent', true)
            ->where('servers.0.address', '203.0.113.5')
            ->where('servers.0.status', 'ready')
        );
});

it('404s the overview for a draft event chosen via ?event=', function () {
    $event = Event::factory()->draft()->create();

    $this->actingAs(User::factory()->create())->get("/servers?event={$event->slug}")
        ->assertNotFound();
});

it('omits servers belonging to other events\' tournaments', function () {
    $event = Event::factory()->live()->create();
    $otherEvent = Event::factory()->live()->create();

    $tournament = Tournament::factory()->for($otherEvent)->create();
    $match = GameMatch::factory()->for($tournament)->create();

    ServerLink::factory()->create([
        'match_id' => $match->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '198.51.100.9', port: 27015),
    ]);

    $this->actingAs(User::factory()->create())->get("/servers?event={$event->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Servers/Index')
            ->has('servers', 0)
        );
});

it('lists event-level preset servers with query info, and not-responding ones, but hides failed ones', function () {
    $event = Event::factory()->live()->create();
    $game = Game::factory()->create(['name' => "Garry's Mod"]);
    $base = ['event_id' => $event->id, 'game_id' => $game->id, 'title' => 'Sommer TTT', 'join_info' => new JoinInfo(address: '203.0.113.5', port: 27015, connectString: 'steam://connect/203.0.113.5:27015')];

    ServerLink::factory()->create($base + [
        'status' => ServerLinkStatus::Ready,
        'query_info' => ['name' => 'Sommer TTT', 'map' => 'ttt_minecraft_b5', 'players' => 4, 'max_players' => 16],
        'queried_at' => now(),
    ]);
    ServerLink::factory()->create(array_merge($base, ['status' => ServerLinkStatus::NotResponding, 'join_info' => new JoinInfo(address: '203.0.113.5', port: 27016, connectString: '203.0.113.5:27016')]));
    ServerLink::factory()->create(array_merge($base, ['status' => ServerLinkStatus::Provisioning, 'join_info' => new JoinInfo]));
    ServerLink::factory()->create(array_merge($base, ['status' => ServerLinkStatus::Failed]));
    ServerLink::factory()->create(array_merge($base, ['status' => ServerLinkStatus::InstallFailed]));
    ServerLink::factory()->create(['status' => ServerLinkStatus::Provisioning, 'match_id' => null]);

    $this->actingAs(User::factory()->create())->get("/servers?event={$event->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('servers', 3)
            ->where('servers.0.game', "Garry's Mod")
            ->where('servers.0.title', 'Sommer TTT')
            ->where('servers.0.query.map', 'ttt_minecraft_b5')
            ->where('servers.0.query.players', 4)
            ->where('servers.0.query.maxPlayers', 16)
            ->where('servers.0.connectIsLink', true)
            ->where('servers.1.status', 'not_responding')
            ->where('servers.1.connectIsLink', false)
            ->where('servers.1.query', null)
            ->where('servers.2.status', 'provisioning')
        );
});

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
            ->component('Servers/Index')
            ->where('permanentLabels.title', 'Dauerserver')
            ->where('permanent.0.title', 'Survival')
            ->where('permanent.0.game', 'Minecraft')
            ->where('permanent.0.address', '203.0.113.20')
            ->where('permanent.0.port', 25565)
            ->where('permanent.0.status', 'ready')
            ->where('permanent.0.query.players', 3)
            ->where('permanent.0.connectIsLink', false)
            ->where('permanent.0.connectString', '203.0.113.20:25565')
            ->where('permanent.0.description', 'Whitelist über die Orga')
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
            ->has('permanent', 3)
            ->where('permanent.0.title', 'A offline')
            ->where('permanent.0.status', 'offline')
            // A switched-off server shows no stale player count.
            ->where('permanent.0.query', null)
            ->where('permanent.1.status', 'not_responding')
            ->where('permanent.2.status', 'provisioning')
        );
});

it('renders an empty permanent list', function () {
    $this->actingAs(User::factory()->create())->get('/servers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('permanent', 0)->where('permanentLabels.empty', 'Es sind noch keine Dauerserver eingerichtet.'));
});

it('does not expose secrets or internals of a server', function () {
    ServerLink::factory()->permanent()->create(['pelican_server_id' => '12', 'pelican_node_id' => '2', 'telemetry_token' => 'tok-secret']);

    $this->actingAs(User::factory()->create())->get('/servers')
        ->assertOk()
        ->assertDontSee('tok-secret')
        ->assertDontSee('pelican_node_id')
        ->assertDontSee('pelican_server_id');
});

it('has the policy matrix: everyone sees, only admins manage', function (Role $role, bool $view, bool $manage) {
    $user = User::factory()->state(['role' => $role])->create();
    $link = ServerLink::factory()->permanent()->create();

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

    $this->actingAs(User::factory()->create())->get('/servers')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('permanent.0.query.players', 2)
            ->where('permanent.0.query.maxPlayers', 16)
            ->where('permanent.0.query.playerList.0.name', 'klaus')
            ->where('permanent.0.query.playerList.1.name', 'Kleintonno')
        );

    auth()->logout();
    $this->get('/servers')->assertRedirect(route('login'));
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

it('redirects guests to login, there is no public page', function () {
    ServerLink::factory()->permanent()->create();

    $this->get('/servers')->assertRedirect(route('login'));
});

it('has no event section without a current event, but still lists the permanent servers', function () {
    ServerLink::factory()->permanent()->create(['title' => 'Survival']);

    $this->actingAs(User::factory()->create())->get('/servers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('event', null)
            ->has('servers', 0)
            ->where('permanent.0.title', 'Survival')
            ->where('overviewLabels.title', 'Server')
        );
});

it('shows the current event with isCurrent and its servers', function () {
    $event = Event::factory()->live()->create();
    ServerLink::factory()->create(['event_id' => $event->id, 'status' => ServerLinkStatus::Ready, 'title' => 'Sommer TTT']);

    $this->actingAs(User::factory()->create())->get('/servers')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('event.slug', $event->slug)
            ->where('event.isCurrent', true)
            ->where('servers.0.title', 'Sommer TTT')
            ->where('servers.0.isMine', false)
        );
});

it('lists a chosen past public event with isCurrent false', function () {
    Event::factory()->live()->create();
    $past = Event::factory()->finished()->create();
    ServerLink::factory()->create(['event_id' => $past->id, 'status' => ServerLinkStatus::Ready, 'title' => 'Alt']);

    $this->actingAs(User::factory()->create())->get("/servers?event={$past->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('event.slug', $past->slug)
            ->where('event.isCurrent', false)
            ->has('servers', 1)
            ->where('servers.0.title', 'Alt')
        );
});

it('404s an unknown event slug', function () {
    $this->actingAs(User::factory()->create())->get('/servers?event=nope')->assertNotFound();
});

it('flags isMine only for a server whose match the viewer plays in', function () {
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    $viewer = User::factory()->create();
    $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $viewer->id, 'team_id' => null]);
    $mine = GameMatch::factory()->for($tournament)->create(['entry1_id' => $entry->id]);
    $foreign = GameMatch::factory()->for($tournament)->create();

    ServerLink::factory()->create(['match_id' => $mine->id, 'status' => ServerLinkStatus::Ready, 'title' => 'Meins']);
    ServerLink::factory()->create(['match_id' => $foreign->id, 'status' => ServerLinkStatus::Ready, 'title' => 'Fremd']);
    ServerLink::factory()->create(['event_id' => $event->id, 'status' => ServerLinkStatus::Ready, 'title' => 'Event']);

    $this->actingAs($viewer)->get('/servers')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('servers', 3)
        ->where('servers', fn ($servers) => collect($servers)->mapWithKeys(fn ($s) => [$s['title'] => $s['isMine']])->all() === ['Meins' => true, 'Fremd' => false, 'Event' => false])
    );
});

it('flags isMine for a team entry when the viewer is on the roster snapshot', function () {
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    $viewer = User::factory()->create();
    $teammate = User::factory()->create();
    $entry = TournamentEntry::factory()->team()->for($tournament)->create([
        'roster_snapshot' => [
            ['user_id' => $teammate->id, 'name' => 'Mate'],
            ['user_id' => $viewer->id, 'name' => 'Viewer'],
        ],
    ]);
    $match = GameMatch::factory()->for($tournament)->create(['entry2_id' => $entry->id]);
    ServerLink::factory()->create(['match_id' => $match->id, 'status' => ServerLinkStatus::Ready, 'title' => 'Teamserver']);

    $this->actingAs($viewer)->get('/servers')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('servers.0.isMine', true)
    );
});

it('404s instead of failing when ?event is an array', function () {
    $this->actingAs(User::factory()->create())->get('/servers?event[]=x')->assertNotFound();
});

it('tells the page whether a current event exists', function () {
    $other = Event::factory()->finished()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get("/servers?event={$other->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('hasCurrent', false)->where('event.isCurrent', false));

    Event::factory()->live()->create();

    $this->actingAs($user)->get("/servers?event={$other->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('hasCurrent', true));
});

it('301-redirects the old event URL to the overview', function () {
    $current = Event::factory()->live()->create();
    $other = Event::factory()->finished()->create();
    $draft = Event::factory()->draft()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get("/events/{$current->slug}/servers")->assertStatus(301)->assertRedirect('/servers');
    $this->actingAs($user)->get("/events/{$other->slug}/servers")->assertStatus(301)->assertRedirect("/servers?event={$other->slug}");
    $this->actingAs($user)->get("/events/{$draft->slug}/servers")->assertNotFound();
});
