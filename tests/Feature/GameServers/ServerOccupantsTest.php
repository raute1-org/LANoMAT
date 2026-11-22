<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Friends\Models\UserBlock;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\ServerListProjection;
use App\Modules\Mcp\Actions\DecideDeviceAuthorization;
use App\Modules\Mcp\Actions\ExchangeDeviceCode;
use App\Modules\Mcp\Actions\StartDeviceAuthorization;
use App\Modules\Mcp\Server\LanomatServer;
use App\Modules\Mcp\Tools\ServersListTool;
use App\Modules\Presence\Domain\GameLocation;
use App\Modules\Presence\Domain\PersonLocation;
use App\Modules\Presence\Support\LocationSnapshot;
use App\Modules\Presence\Support\LocationSnapshotStore;
use App\Modules\Registration\Models\EventRegistration;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../WebVoice/NativeClient/PlayHelpers.php';

uses(RefreshDatabase::class);

/**
 * The occupant line of a server row: which LANoMAT accounts Presence places on this server
 * link, as the viewer may see them, and the promise that no bearer API carries them.
 *
 * The location snapshot is written directly (the read model is cache-only), the same way
 * LocatedGamesTest arranges it; the refresh itself is Presence's own test surface.
 */

/** Counts the location-snapshot cache reads of one request. */
final class OccupantsCountingCache extends Repository
{
    public int $snapshotReads = 0;

    public function __construct()
    {
        parent::__construct(new ArrayStore);
    }

    public function get($key, $default = null): mixed
    {
        if ($key === 'presence.locations.snapshot') {
            $this->snapshotReads++;
        }

        return parent::get($key, $default);
    }
}

function occupantsEvent(): Event
{
    return Event::factory()->live()->create();
}

/** A registered participant of the event, optionally with user columns set. */
function occupantsParticipant(Event $event, string $name, array $overrides = []): User
{
    $user = User::factory()->create($overrides + ['name' => $name]);

    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);

    return $user;
}

function occupantsGame(string $slug = 'tiny-racer', string $name = 'Tiny Racer'): Game
{
    return Game::factory()->create(['slug' => $slug, 'name' => $name]);
}

/** An event-level server that answers, reachable at `203.0.113.5:7777`. */
function occupantsServer(Event $event, ?Game $game = null, array $overrides = []): ServerLink
{
    return ServerLink::factory()->create($overrides + [
        'event_id' => $event->id,
        'game_id' => ($game ?? Game::factory()->create())->id,
        'title' => 'Tiny Racer Server',
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.5', port: 7777),
        'query_info' => ['name' => 'Tiny Racer Server', 'map' => 'arena', 'players' => 1, 'max_players' => 16],
        'queried_at' => now(),
    ]);
}

/**
 * Puts these users on the server link in the cached location snapshot.
 *
 * @param  list<int>  $userIds
 */
function occupantsSnapshot(int $linkId, array $userIds, string $game = 'Tiny Racer'): void
{
    $people = [];

    foreach ($userIds as $userId) {
        $people[$userId] = new PersonLocation($userId, [], new GameLocation(
            GameLocation::SOURCE_QUERY,
            $game,
            'Tiny Racer Server',
            '203.0.113.5',
            7777,
            $linkId,
            now()->toIso8601String(),
        ));
    }

    app(LocationSnapshotStore::class)->put(new LocationSnapshot(now()->toIso8601String(), $people));
}

/** A short-lived agent token for the MCP bearer API. */
function occupantsToken(User $user): string
{
    $pair = app(StartDeviceAuthorization::class)->handle('server-occupants-test');

    app(DecideDeviceAuthorization::class)->handle($user, $pair['user_code'], true);

    return app(ExchangeDeviceCode::class)->handle($pair['device_code'])['access_token'];
}

/**
 * @param  array<string, mixed>  $occupants
 * @return list<string>
 */
function occupantsNames(mixed $occupants): array
{
    if (! is_array($occupants) || ! is_array($occupants['people'] ?? null)) {
        return [];
    }

    return array_values(array_map(static fn (array $person): string => (string) $person['name'], $occupants['people']));
}

// ---------------------------------------------------------------- the list

it('lists the accounts the viewer may see on a server and counts the rest', function () {
    $event = occupantsEvent();
    $server = occupantsServer($event);

    $names = ['Jona', 'anna', 'Cem', 'Dana', 'Emil', 'Feli', 'Gustav', 'Hanna', 'Ida', 'Bea'];

    foreach ($names as $name) {
        occupantsParticipant($event, $name);
    }

    $users = User::query()->orderBy('id')->get()->keyBy('name');
    $users['anna']->forceFill(['avatar_path' => 'avatars/anna.webp', 'avatar_hash' => 'abcdef0123456789'])->save();

    occupantsSnapshot($server->id, User::query()->pluck('id')->map(fn ($id): int => (int) $id)->all());

    $this->actingAs($users['Ida'])
        ->get("/servers?event={$event->slug}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($users): void {
            $occupants = $page->toArray()['props']['servers'][0]['occupants'];

            // Eight by name (case-insensitive), the other two counted, not named.
            expect(occupantsNames($occupants))
                ->toBe(['anna', 'Bea', 'Cem', 'Dana', 'Emil', 'Feli', 'Gustav', 'Hanna'])
                ->and($occupants['more'])->toBe(2);

            // The avatar the people page renders (the local avatar route), and the account id.
            $anna = collect($occupants['people'])->firstWhere('name', 'anna');

            expect($anna['id'])->toBe($users['anna']->id)
                ->and($anna['avatarUrl'])->toBe("/avatars/{$users['anna']->id}/abcdef0123456789");
        });
});

it('hides somebody who switched share_location off, but the viewer always sees themselves', function () {
    $event = occupantsEvent();
    $server = occupantsServer($event);

    $max = occupantsParticipant($event, 'Max');
    $ada = occupantsParticipant($event, 'Ada');
    $bea = occupantsParticipant($event, 'Bea');

    $max->forceFill(['share_location' => false])->save();
    $ada->forceFill(['share_location' => false])->save();

    occupantsSnapshot($server->id, [$max->id, $ada->id, $bea->id]);

    // Max sees himself (he opted out) and Bea; Ada is neither listed nor counted.
    $this->actingAs($max)
        ->get("/servers?event={$event->slug}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $occupants = $page->toArray()['props']['servers'][0]['occupants'];

            expect(occupantsNames($occupants))->toBe(['Bea', 'Max'])
                ->and($occupants['more'])->toBe(0);
        });

    // Bea sees only herself: Max and Ada both opted out.
    $this->actingAs($bea)
        ->get("/servers?event={$event->slug}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            expect(occupantsNames($page->toArray()['props']['servers'][0]['occupants']))->toBe(['Bea']);
        });
});

it('leaves out people blocked either way with the viewer', function () {
    $event = occupantsEvent();
    $server = occupantsServer($event);

    $max = occupantsParticipant($event, 'Max');
    $ada = occupantsParticipant($event, 'Ada');
    $bea = occupantsParticipant($event, 'Bea');
    $cem = occupantsParticipant($event, 'Cem');

    UserBlock::factory()->create(['blocker_id' => $max->id, 'blocked_id' => $ada->id]);
    UserBlock::factory()->create(['blocker_id' => $bea->id, 'blocked_id' => $max->id]);

    occupantsSnapshot($server->id, [$max->id, $ada->id, $bea->id, $cem->id]);

    $this->actingAs($max)
        ->get("/servers?event={$event->slug}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            expect(occupantsNames($page->toArray()['props']['servers'][0]['occupants']))->toBe(['Cem', 'Max']);
        });
});

it('shows nobody who is only playing another game', function () {
    $event = occupantsEvent();
    $server = occupantsServer($event);
    $other = occupantsServer($event, overrides: ['title' => 'Anderer Server']);

    $anna = occupantsParticipant($event, 'Anna');
    $bea = occupantsParticipant($event, 'Bea');

    occupantsSnapshot($server->id, [$anna->id]);
    $snapshot = app(LocationSnapshotStore::class)->get();
    $people = $snapshot->people;
    $people[$bea->id] = new PersonLocation($bea->id, [], new GameLocation(GameLocation::SOURCE_QUERY, 'Tiny Racer', 'Anderer Server', '203.0.113.6', 7778, $other->id, now()->toIso8601String()));
    app(LocationSnapshotStore::class)->put(new LocationSnapshot(now()->toIso8601String(), $people));

    $this->actingAs($anna)
        ->get("/servers?event={$event->slug}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $rows = $page->toArray()['props']['servers'];

            // Everybody is on exactly one server: the other row names its own player.
            expect(occupantsNames($rows[0]['occupants']))->toBe(['Anna'])
                ->and(occupantsNames($rows[1]['occupants']))->toBe(['Bea']);
        });
});

// ---------------------------------------------------------------- while nobody may see anything

it('carries null occupants for a guest', function () {
    $event = occupantsEvent();
    $server = occupantsServer($event);
    $anna = occupantsParticipant($event, 'Anna');

    occupantsSnapshot($server->id, [$anna->id]);

    // No logged-in viewer: the projection has nothing to ask the read model.
    expect(ServerListProjection::dto($server->fresh())['occupants'])->toBeNull();
});

it('tells somebody who is not a participant of the event nothing', function () {
    $event = occupantsEvent();
    $server = occupantsServer($event);
    $anna = occupantsParticipant($event, 'Anna');
    $outsider = User::factory()->create(['name' => 'Olli Outsider']);
    $orga = User::factory()->orga()->create();

    occupantsSnapshot($server->id, [$anna->id]);

    // An account alone is not the audience: the line follows the event's
    // people list (participants and orga), as the share_location setting says.
    $this->actingAs($outsider)
        ->get("/servers?event={$event->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('servers.0.occupants', null))
        ->assertDontSee('Anna');

    $this->actingAs($orga)
        ->get("/servers?event={$event->slug}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            expect(occupantsNames($page->toArray()['props']['servers'][0]['occupants']))->toBe(['Anna']);
        });
});

it('carries null occupants while the presence module is off', function () {
    $event = occupantsEvent();
    $server = occupantsServer($event);
    $anna = occupantsParticipant($event, 'Anna');

    occupantsSnapshot($server->id, [$anna->id]);

    // Swapped after the arrangement: the request runs with Presence off, so the
    // contract resolves to the null implementation. Friends and Recap require
    // Presence, so they go off with it.
    app()->instance(Modules::class, new Modules('full', [
        Module::Presence->key() => false,
        Module::Friends->key() => false,
        Module::Recap->key() => false,
    ]));

    $this->actingAs($anna)
        ->get("/servers?event={$event->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('servers', 1)
            ->has('servers.0.occupants')
            ->where('servers.0.occupants', null));
});

// ---------------------------------------------------------------- the bearer APIs

it('carries no people in the agent API', function () {
    $event = occupantsEvent();
    $server = occupantsServer($event);
    $anna = occupantsParticipant($event, 'Anna');

    occupantsSnapshot($server->id, [$anna->id]);

    $response = $this->withToken(occupantsToken(User::factory()->create()))
        ->getJson('/api/v1/servers')
        ->assertOk()
        ->assertJsonPath('servers.0.id', $server->id)
        ->assertJsonPath('servers.0.occupants', null);

    // Not even the account name travels: this answer is per-token, never per-viewer.
    expect($response->getContent())->not->toContain('Anna');
});

it('carries no people in the native client servers API', function () {
    $event = occupantsEvent();
    $server = occupantsServer($event);
    $anna = occupantsParticipant($event, 'Anna');

    occupantsSnapshot($server->id, [$anna->id]);

    $response = $this->getJson("/api/voice-client/v1/events/{$event->slug}/servers", playBearer($anna))
        ->assertOk()
        ->assertJsonPath('servers.0.id', $server->id);

    expect($response->json('servers.0'))->not->toHaveKey('occupants')
        ->and($response->getContent())->not->toContain('Anna');
});

it('carries no people in the servers_list tool', function () {
    $event = occupantsEvent();
    occupantsServer($event);
    $anna = occupantsParticipant($event, 'Anna');

    occupantsSnapshot(ServerLink::query()->value('id'), [$anna->id]);

    LanomatServer::actingAs($anna);

    LanomatServer::tool(ServersListTool::class, ['event_slug' => $event->slug])
        ->assertOk()
        ->assertStructuredContentPath('servers', fn (mixed $servers): bool => is_array($servers)
            && $servers !== []
            && ! array_key_exists('occupants', $servers[0]));
});

// ---------------------------------------------------------------- one read per request

it('reads the location snapshot once for a server page with ten rows', function () {
    $event = occupantsEvent();
    $game = occupantsGame();
    $anna = occupantsParticipant($event, 'Anna');
    $bea = occupantsParticipant($event, 'Bea');

    $links = [];

    foreach (range(0, 9) as $index) {
        $links[] = occupantsServer($event, $game, ['title' => 'Server '.$index]);
    }

    // Count the reads of the snapshot cache slot of this one request.
    $cache = new OccupantsCountingCache;
    Cache::extend('occupants-counting', fn (): OccupantsCountingCache => $cache);
    Config::set('cache.stores.occupants-counting', ['driver' => 'occupants-counting']);
    Config::set('cache.default', 'occupants-counting');
    Cache::forgetDriver('occupants-counting');

    occupantsSnapshot($links[0]->id, [$anna->id, $bea->id]);

    $this->actingAs($anna)
        ->get("/servers?event={$event->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('servers', 10)
            ->where('servers.0.occupants.people.0.name', 'Anna'));

    expect($cache->snapshotReads)->toBe(1);
});

// ---------------------------------------------------------------- the page props

it('carries the occupant line through the global server browser', function () {
    $event = occupantsEvent();
    $server = occupantsServer($event);
    $anna = occupantsParticipant($event, 'Anna');
    $bea = occupantsParticipant($event, 'Bea');

    occupantsSnapshot($server->id, [$anna->id, $bea->id]);

    $this->actingAs($anna)
        ->get('/servers/all')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $occupants = $page->toArray()['props']['servers'][0]['occupants'];

            expect(occupantsNames($occupants))->toBe(['Anna', 'Bea'])
                ->and($occupants['more'])->toBe(0);
        });
});

it('keeps the global browser scoped to the current event', function () {
    // A past event's server is listed by the browser, but the people shown are the
    // ones of the event running now (the snapshot is "where is everybody right now").
    $current = occupantsEvent();
    $past = Event::factory()->finished()->create(['starts_at' => now()->subDays(40), 'ends_at' => now()->subDays(39)]);

    $server = occupantsServer($past);
    $anna = occupantsParticipant($current, 'Anna');

    occupantsSnapshot($server->id, [$anna->id]);

    $this->actingAs($anna)
        ->get('/servers/all')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            expect(occupantsNames($page->toArray()['props']['servers'][0]['occupants']))->toBe(['Anna']);
        });
});
