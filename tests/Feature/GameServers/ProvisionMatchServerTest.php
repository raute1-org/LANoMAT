<?php

use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\GameServers\Jobs\PollServerStatusJob;
use App\Modules\GameServers\Jobs\ProvisionMatchServerJob;
use App\Modules\GameServers\Listeners\ProvisionMatchServerOnReady;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function readyMatchWithEggGame(): GameMatch
{
    $game = Game::factory()->create([
        'pelican_egg_id' => 'egg-42',
        'default_server_config' => new ServerConfig(maxPlayers: 10, map: 'de_dust2'),
    ]);
    $tournament = Tournament::factory()->create(['game_id' => $game->id]);

    return GameMatch::factory()->for($tournament)->create();
}

function readyMatchWithoutGame(): GameMatch
{
    $tournament = Tournament::factory()->create(['game_id' => null]);

    return GameMatch::factory()->for($tournament)->create();
}

it('dispatches ProvisionMatchServerJob when the tournament game has a pelican egg id', function () {
    Bus::fake();

    $match = readyMatchWithEggGame();

    (new ProvisionMatchServerOnReady)->handle(new MatchReady($match));

    Bus::assertDispatched(ProvisionMatchServerJob::class, fn ($job) => $job->matchId === $match->id);
});

it('does not dispatch ProvisionMatchServerJob when the game has no pelican egg id (manual mode)', function () {
    Bus::fake();

    $match = readyMatchWithoutGame();

    (new ProvisionMatchServerOnReady)->handle(new MatchReady($match));

    Bus::assertNotDispatched(ProvisionMatchServerJob::class);
});

it('creates a ServerLink and calls createServer with the game config on the fake Pelican client', function () {
    Bus::fake([PollServerStatusJob::class]);
    $fake = fakePelican();

    $match = readyMatchWithEggGame();

    (new ProvisionMatchServerJob($match->id))->handle($fake);

    $fake->assertServerCreated('egg-42');

    $match->refresh();
    expect($match->server_link_id)->not->toBeNull();

    $link = $match->serverLink;
    expect($link->status)->toBe(ServerLinkStatus::Provisioning)
        ->and($link->pelican_server_id)->not->toBeNull()
        ->and($fake->created[0]['config'])->toMatchArray(['max_players' => 10, 'map' => 'de_dust2']);
});

it('dispatches a delayed PollServerStatusJob after creating the server', function () {
    Bus::fake();
    $fake = fakePelican();

    $match = readyMatchWithEggGame();

    (new ProvisionMatchServerJob($match->id))->handle($fake);

    Bus::assertDispatched(PollServerStatusJob::class);
});

it('is idempotent: re-running ProvisionMatchServerJob after a link exists is a no-op', function () {
    Bus::fake([PollServerStatusJob::class]);
    $fake = fakePelican();

    $match = readyMatchWithEggGame();

    (new ProvisionMatchServerJob($match->id))->handle($fake);
    $createdCount = count($fake->created);

    (new ProvisionMatchServerJob($match->id))->handle($fake);

    expect($fake->created)->toHaveCount($createdCount);
});

it('claims the slot transactionally: exactly one ServerLink row and one createServer call survive a re-dispatch for the same match', function () {
    // Guards against the double-provision race: the guard-then-write used to
    // be check-then-act (null-check, then later writes), which two
    // concurrent dispatches for the same match (duplicate MatchReady from a
    // retry, or a manual re-dispatch) could both pass before either wrote
    // server_link_id. The fix claims the ServerLink row + match.server_link_id
    // inside a single DB::transaction with match.lockForUpdate(), committed
    // *before* the external Pelican call — so this asserts the committed
    // single-row invariant plus the no-op re-run on top of it.
    Bus::fake([PollServerStatusJob::class]);
    $fake = fakePelican();

    $match = readyMatchWithEggGame();

    (new ProvisionMatchServerJob($match->id))->handle($fake);

    expect(ServerLink::query()->where('match_id', $match->id)->count())->toBe(1)
        ->and($fake->created)->toHaveCount(1);

    $match->refresh();
    expect($match->server_link_id)->not->toBeNull();

    // A second job instance for the same match (simulating a duplicate
    // MatchReady dispatch or a manual re-dispatch) must see the slot already
    // claimed and no-op entirely: no additional ServerLink row, no
    // additional createServer call.
    (new ProvisionMatchServerJob($match->id))->handle($fake);

    expect(ServerLink::query()->where('match_id', $match->id)->count())->toBe(1)
        ->and($fake->created)->toHaveCount(1);
});

it('marks the ServerLink Failed when createServer throws after the slot has already been claimed', function () {
    Bus::fake([PollServerStatusJob::class]);
    $fake = fakePelican();
    $fake->failNextCreateWith(new RuntimeException('Pelican API unreachable'));

    $match = readyMatchWithEggGame();

    expect(fn () => (new ProvisionMatchServerJob($match->id))->handle($fake))
        ->toThrow(RuntimeException::class, 'Pelican API unreachable');

    // The slot claim (ServerLink row + match.server_link_id) was already
    // committed before the external call, so it must still exist — just
    // flipped to Failed rather than left dangling in Provisioning.
    $match->refresh();
    expect($match->server_link_id)->not->toBeNull();

    $link = $match->serverLink;
    expect($link->status)->toBe(ServerLinkStatus::Failed);

    expect(ServerLink::query()->where('match_id', $match->id)->count())->toBe(1);
});

it('transitions Provisioning to Ready and writes JoinInfo when the poll job sees the server running', function () {
    Event::fake([ServerLinkUpdated::class]);
    // ProvisionMatchServerJob itself dispatches a PollServerStatusJob; under
    // the sync queue driver used in tests that would otherwise run inline
    // immediately (while the fake server is still Provisioning) and busy-loop
    // via the "still installing" re-dispatch branch. Fake it here so this
    // test drives PollServerStatusJob exactly once, itself, after flipping
    // the fake server to Running.
    Bus::fake([PollServerStatusJob::class]);
    $fake = fakePelican();

    $match = readyMatchWithEggGame();
    (new ProvisionMatchServerJob($match->id))->handle($fake);

    $match->refresh();
    $link = $match->serverLink;

    $fake->setState($link->pelican_server_id, ServerState::Running);

    (new PollServerStatusJob($link->id))->handle($fake);

    $link->refresh();
    expect($link->status)->toBe(ServerLinkStatus::Ready);

    Event::assertDispatched(ServerLinkUpdated::class, fn ($event) => $event->serverLink->id === $link->id);
});

it('re-dispatches a delayed poll when the server is still installing', function () {
    Bus::fake();
    $fake = fakePelican();

    $match = readyMatchWithEggGame();
    (new ProvisionMatchServerJob($match->id))->handle($fake);

    $match->refresh();
    $link = $match->serverLink;

    $fake->setState($link->pelican_server_id, ServerState::Installing);

    (new PollServerStatusJob($link->id))->handle($fake);

    $link->refresh();
    expect($link->status)->toBe(ServerLinkStatus::Provisioning);

    Bus::assertDispatched(PollServerStatusJob::class, fn ($job) => $job->serverLinkId === $link->id && $job->attempt === 2);
});

it('marks the ServerLink Failed once the retry budget is exhausted while still installing', function () {
    Bus::fake();
    $fake = fakePelican();

    $match = readyMatchWithEggGame();
    (new ProvisionMatchServerJob($match->id))->handle($fake);

    $match->refresh();
    $link = $match->serverLink;

    $fake->setState($link->pelican_server_id, ServerState::Installing);

    (new PollServerStatusJob($link->id, attempt: 30))->handle($fake);

    $link->refresh();
    expect($link->status)->toBe(ServerLinkStatus::Failed);

    Bus::assertNotDispatched(PollServerStatusJob::class, fn ($job) => $job->serverLinkId === $link->id && $job->attempt === 31);
});

it('marks the ServerLink Failed when the server reports Failed', function () {
    Event::fake([ServerLinkUpdated::class]);
    // See the "transitions Provisioning to Ready" test above for why the
    // job's own PollServerStatusJob dispatch must be faked here too.
    Bus::fake([PollServerStatusJob::class]);
    $fake = fakePelican();

    $match = readyMatchWithEggGame();
    (new ProvisionMatchServerJob($match->id))->handle($fake);

    $match->refresh();
    $link = $match->serverLink;

    $fake->setState($link->pelican_server_id, ServerState::Failed);

    (new PollServerStatusJob($link->id))->handle($fake);

    $link->refresh();
    expect($link->status)->toBe(ServerLinkStatus::Failed);
});
