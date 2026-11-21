<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Mcp\Actions\DecideDeviceAuthorization;
use App\Modules\Mcp\Actions\ExchangeDeviceCode;
use App\Modules\Mcp\Actions\StartDeviceAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

/** A short-lived agent token for the bearer API. */
function serverApiToken(User $user): string
{
    $pair = app(StartDeviceAuthorization::class)->handle('server-api-test');

    app(DecideDeviceAuthorization::class)->handle($user, $pair['user_code'], true);

    return app(ExchangeDeviceCode::class)->handle($pair['device_code'])['access_token'];
}

function serverApiEventServer(Event $event, array $overrides = []): ServerLink
{
    return ServerLink::factory()->create($overrides + [
        'event_id' => $event->id,
        'title' => 'Survival',
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.9', port: 25565),
    ]);
}

it('requires a bearer token', function () {
    $this->getJson('/api/v1/servers')->assertUnauthorized();
    $this->getJson('/api/v1/servers', ['Authorization' => 'Bearer garbage'])->assertUnauthorized();

    $this->getJson('/api/v1/servers', ['Authorization' => 'Bearer '.serverApiToken(User::factory()->create())])
        ->assertOk();
});

it('aggregates the live event servers and the permanent servers', function () {
    $event = Event::factory()->live()->create();
    $game = Game::factory()->create(['name' => 'Minecraft']);

    $eventServer = serverApiEventServer($event, [
        'game_id' => $game->id,
        'description' => 'Whitelist off',
        'query_info' => [
            'name' => 'Survival',
            'map' => 'world',
            'players' => 3,
            'max_players' => 20,
            'player_names' => ['Ada-Player'],
        ],
        'queried_at' => now(),
    ]);

    $permanent = ServerLink::factory()->permanent()->create([
        'title' => 'Dauerwelt',
        'status' => ServerLinkStatus::Offline,
    ]);

    $response = $this->withToken(serverApiToken(User::factory()->create()))
        ->getJson('/api/v1/servers')
        ->assertOk()
        ->assertJsonPath('events.0.slug', $event->slug)
        ->assertJsonPath('events.0.status', 'live')
        ->assertJsonPath('servers.0.id', $eventServer->id)
        ->assertJsonPath('servers.0.title', 'Survival')
        ->assertJsonPath('servers.0.game', 'Minecraft')
        ->assertJsonPath('servers.0.event.slug', $event->slug)
        ->assertJsonPath('servers.0.permanent', false)
        ->assertJsonPath('servers.0.query.players', 3)
        ->assertJsonPath('servers.0.query.maxPlayers', 20)
        ->assertJsonPath('servers.1.id', $permanent->id)
        ->assertJsonPath('servers.1.permanent', true)
        ->assertJsonPath('servers.1.event', null)
        ->assertJsonPath('servers.1.status', 'offline');

    // No user data or per-viewer player names travel through this endpoint.
    expect($response->getContent())
        ->not->toContain('Ada-Player')
        ->not->toContain('playerList')
        ->not->toContain('requested_by')
        ->and($response->json('servers.0.query'))->not->toHaveKey('playerList');
});

it('includes a recently finished event but not a stale one, a draft or an archived event', function () {
    $recent = Event::factory()->finished()->create([
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->subDay(),
    ]);
    $stale = Event::factory()->finished()->create([
        'starts_at' => now()->subDays(90),
        'ends_at' => now()->subDays(89),
    ]);
    $draft = Event::factory()->draft()->create();
    $archived = Event::factory()->archived()->create([
        'starts_at' => now()->subDays(3),
        'ends_at' => now()->subDays(2),
    ]);

    $recentServer = serverApiEventServer($recent, ['title' => 'Recent']);
    $staleServer = serverApiEventServer($stale, ['title' => 'Stale']);
    $draftServer = serverApiEventServer($draft, ['title' => 'Draft']);
    $archivedServer = serverApiEventServer($archived, ['title' => 'Archived']);

    $response = $this->withToken(serverApiToken(User::factory()->create()))
        ->getJson('/api/v1/servers')
        ->assertOk();

    $ids = collect($response->json('servers'))->pluck('id')->all();
    $slugs = collect($response->json('events'))->pluck('slug')->all();

    expect($ids)->toContain($recentServer->id)
        ->not->toContain($staleServer->id)
        ->not->toContain($draftServer->id)
        ->not->toContain($archivedServer->id)
        ->and($slugs)->toContain($recent->slug)
        ->not->toContain($stale->slug)
        ->not->toContain($draft->slug)
        ->not->toContain($archived->slug);
});

it('lists permanent servers of every status the web page shows, including offline ones', function () {
    $ready = ServerLink::factory()->permanent()->create(['title' => 'Ready']);
    $offline = ServerLink::factory()->permanent()->create([
        'title' => 'Offline',
        'status' => ServerLinkStatus::Offline,
    ]);
    $provisioning = ServerLink::factory()->permanent()->create([
        'title' => 'Startet',
        'status' => ServerLinkStatus::Provisioning,
        'source' => ServerSource::Pelican,
    ]);
    $failed = ServerLink::factory()->permanent()->create([
        'title' => 'Kaputt',
        'status' => ServerLinkStatus::Failed,
    ]);

    $response = $this->withToken(serverApiToken(User::factory()->create()))
        ->getJson('/api/v1/servers')
        ->assertOk();

    $titles = collect($response->json('servers'))->pluck('title')->all();

    expect($titles)->toContain($ready->title)
        ->toContain($offline->title)
        ->toContain($provisioning->title)
        ->not->toContain($failed->title)
        ->and(collect($response->json('servers'))->every(fn (array $server): bool => $server['permanent'] === true))->toBeTrue();
});

it('answers with an empty aggregate when no event or server exists', function () {
    $this->withToken(serverApiToken(User::factory()->create()))
        ->getJson('/api/v1/servers')
        ->assertOk()
        ->assertExactJson(['events' => [], 'servers' => []]);
});

it('registers the route only while the GameServers module is on', function () {
    expect(Route::has('mcp.api.servers.index'))->toBeTrue();

    $process = new Process(
        [PHP_BINARY, 'artisan', 'route:list', '--json'],
        base_path(),
        ['MODULES_PROFILE' => 'full', 'MODULE_GAME_SERVERS' => 'false', 'APP_ENV' => 'testing'],
    );
    $process->mustRun();

    $names = collect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))
        ->pluck('name');

    expect($names)->not->toContain('mcp.api.servers.index');
});
