<?php

use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\ServerGameSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $query
 */
function summaryLink(?Event $event, ?Game $game, array $attributes = [], array $query = [], ?JoinInfo $join = null): ServerLink
{
    return ServerLink::factory()->create($attributes + [
        'event_id' => $event?->id,
        'game_id' => $game?->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => $join ?? new JoinInfo(address: '198.51.100.1', port: 27015, connectString: 'steam://connect/198.51.100.1:27015'),
        'query_info' => $query === [] ? null : $query,
    ]);
}

/** @return array<string, mixed> */
function queryInfo(int $players, int $maxPlayers): array
{
    return ['name' => 'Server', 'map' => 'de_dust2', 'players' => $players, 'max_players' => $maxPlayers];
}

it('groups the event servers per game and sums players and slots', function () {
    $event = Event::factory()->live()->create();
    $cs = Game::factory()->create(['name' => 'Counter-Strike 2']);
    $ut = Game::factory()->create(['name' => 'UT2004']);

    summaryLink($event, $cs, ['status' => ServerLinkStatus::Ready], queryInfo(3, 10));
    summaryLink($event, $cs, ['status' => ServerLinkStatus::NotResponding], queryInfo(1, 8));
    summaryLink($event, $ut, ['status' => ServerLinkStatus::Ready], queryInfo(4, 16));

    $summary = ServerGameSummary::forEvent($event);

    expect($summary)->toHaveCount(2)
        ->and($summary[0])->toMatchArray(['game' => 'Counter-Strike 2', 'servers' => 2, 'players' => 4, 'maxPlayers' => 18])
        ->and($summary[1])->toMatchArray(['game' => 'UT2004', 'servers' => 1, 'players' => 4, 'maxPlayers' => 16]);
    expect(ServerGameSummary::totalPlayers($event))->toBe(8);
});

it('counts only ready and not-responding servers', function () {
    $event = Event::factory()->live()->create();
    $game = Game::factory()->create(['name' => 'CS2']);

    summaryLink($event, $game, ['status' => ServerLinkStatus::Ready], queryInfo(2, 10));
    summaryLink($event, $game, ['status' => ServerLinkStatus::Provisioning], queryInfo(9, 10));
    summaryLink($event, $game, ['status' => ServerLinkStatus::Stopped], queryInfo(9, 10));
    summaryLink($event, $game, ['status' => ServerLinkStatus::Failed], queryInfo(9, 10));
    summaryLink($event, $game, ['status' => ServerLinkStatus::Pending], queryInfo(9, 10));

    $summary = ServerGameSummary::forEvent($event);

    expect($summary)->toHaveCount(1)
        ->and($summary[0])->toMatchArray(['servers' => 1, 'players' => 2, 'maxPlayers' => 10])
        ->and(ServerGameSummary::totalPlayers($event))->toBe(2);
});

it('merges permanent servers into the event summary and counts a server once', function () {
    $event = Event::factory()->live()->create();
    $game = Game::factory()->create(['name' => 'Garry\'s Mod']);

    summaryLink($event, $game, [], queryInfo(5, 24));
    ServerLink::factory()->permanent()->create([
        'game_id' => $game->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(connectString: 'steam://connect/198.51.100.7:27015'),
        'query_info' => queryInfo(2, 16),
    ]);
    // Permanent servers that are also linked to the event must not be counted twice.
    $shared = ServerLink::factory()->permanent()->create([
        'event_id' => $event->id,
        'game_id' => $game->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(connectString: 'steam://connect/198.51.100.8:27015'),
        'query_info' => queryInfo(1, 8),
    ]);

    $summary = ServerGameSummary::forEvent($event);

    expect($summary)->toHaveCount(1)
        ->and($summary[0])->toMatchArray(['game' => 'Garry\'s Mod', 'servers' => 3, 'players' => 8, 'maxPlayers' => 48])
        ->and(ServerGameSummary::totalPlayers($event))->toBe(8)
        ->and($shared->id)->toBeInt();
});

it('excludes offline permanent servers from the summary', function () {
    $game = Game::factory()->create(['name' => 'Minecraft']);

    ServerLink::factory()->permanent()->create([
        'game_id' => $game->id,
        'status' => ServerLinkStatus::Offline,
        'query_info' => queryInfo(3, 20),
    ]);

    expect(ServerGameSummary::forEvent(null))->toBe([])
        ->and(ServerGameSummary::totalPlayers(null))->toBe(0);
});

it('picks the joinable server with free slots and the most players, lowest id on a tie', function () {
    $event = Event::factory()->live()->create();
    $game = Game::factory()->create(['name' => 'CS2']);

    summaryLink($event, $game, ['title' => 'Lobby 1'], queryInfo(5, 10));
    $fullButBusiest = summaryLink($event, $game, ['title' => 'Voll'], queryInfo(10, 10));
    $tieFirst = summaryLink($event, $game, ['title' => 'Lobby 2'], queryInfo(7, 10));
    summaryLink($event, $game, ['title' => 'Lobby 3'], queryInfo(7, 10));

    $join = ServerGameSummary::forEvent($event)[0]['join'];

    expect($fullButBusiest->id)->toBeInt()
        ->and($join)->toMatchArray([
            'serverId' => $tieFirst->id,
            'title' => 'Lobby 2',
            'connectString' => 'steam://connect/198.51.100.1:27015',
            'connectIsLink' => true,
            'address' => '198.51.100.1',
            'port' => 27015,
            'players' => 7,
            'maxPlayers' => 10,
        ]);
});

it('falls back to the game name as join title when the server has no title', function () {
    $event = Event::factory()->live()->create();
    $game = Game::factory()->create(['name' => 'CS2']);

    summaryLink($event, $game, ['title' => null], queryInfo(1, 10));

    expect(ServerGameSummary::forEvent($event)[0]['join'])->toMatchArray(['title' => 'CS2']);
});

it('has no join when no server of the game has a connect string or free slots', function () {
    $event = Event::factory()->live()->create();
    $noConnect = Game::factory()->create(['name' => 'Ohne Connect']);
    $full = Game::factory()->create(['name' => 'Voll']);

    summaryLink($event, $noConnect, [], queryInfo(1, 10), new JoinInfo);
    summaryLink($event, $full, [], queryInfo(10, 10));

    $summary = collect(ServerGameSummary::forEvent($event))->keyBy('game');

    expect($summary['Ohne Connect'])->toMatchArray(['servers' => 1, 'players' => 1, 'join' => null])
        ->and($summary['Voll'])->toMatchArray(['servers' => 1, 'players' => 10, 'join' => null]);
});

it('groups a server without a game under its title and skips servers with neither', function () {
    $event = Event::factory()->live()->create();

    summaryLink($event, null, ['title' => 'Freie Runde'], queryInfo(2, 12));
    summaryLink($event, null, ['title' => null], queryInfo(9, 12));

    $summary = ServerGameSummary::forEvent($event);

    expect($summary)->toHaveCount(1)
        ->and($summary[0])->toMatchArray(['game' => 'Freie Runde', 'servers' => 1, 'players' => 2]);
});

it('counts zero players and slots for a server without query data and still offers it to join', function () {
    $event = Event::factory()->live()->create();
    $game = Game::factory()->create(['name' => 'CS2']);

    summaryLink($event, $game, [], []);

    expect(ServerGameSummary::forEvent($event)[0])->toMatchArray(['servers' => 1, 'players' => 0, 'maxPlayers' => 0])
        ->and(ServerGameSummary::forEvent($event)[0]['join'])->toMatchArray([
            'connectString' => 'steam://connect/198.51.100.1:27015',
            'players' => 0,
            'maxPlayers' => 0,
        ]);
});

it('never picks a server whose query is known to be full, even when no other server is joinable', function () {
    $event = Event::factory()->live()->create();
    $game = Game::factory()->create(['name' => 'CS2']);

    summaryLink($event, $game, ['title' => 'Voll'], queryInfo(16, 16));
    summaryLink($event, $game, ['title' => 'Unbekannt'], []);

    expect(ServerGameSummary::forEvent($event)[0]['join'])->toMatchArray(['title' => 'Unbekannt']);
});

it('ranks a server with query data above one without it at equal players', function () {
    $event = Event::factory()->live()->create();
    $game = Game::factory()->create(['name' => 'CS2']);

    $unknown = summaryLink($event, $game, ['title' => 'Unbekannt'], []);
    $known = summaryLink($event, $game, ['title' => 'Bekannt'], queryInfo(0, 10));

    expect(ServerGameSummary::forEvent($event)[0]['join'])->toMatchArray(['serverId' => $known->id])
        ->and($unknown->id)->toBeInt();
});

it('prefers a ready server over a not-responding one even when the latter has more players', function () {
    $event = Event::factory()->live()->create();
    $game = Game::factory()->create(['name' => 'CS2']);

    summaryLink($event, $game, ['status' => ServerLinkStatus::NotResponding, 'title' => 'Wartet'], queryInfo(9, 10));
    $ready = summaryLink($event, $game, ['status' => ServerLinkStatus::Ready, 'title' => 'Bereit'], queryInfo(2, 10));

    expect(ServerGameSummary::forEvent($event)[0]['join'])->toMatchArray(['serverId' => $ready->id, 'title' => 'Bereit']);
});

it('falls back to a not-responding server when no ready server is joinable', function () {
    $event = Event::factory()->live()->create();
    $game = Game::factory()->create(['name' => 'CS2']);

    summaryLink($event, $game, ['status' => ServerLinkStatus::Ready, 'title' => 'Voll'], queryInfo(10, 10));
    $waiting = summaryLink($event, $game, ['status' => ServerLinkStatus::NotResponding, 'title' => 'Wartet'], queryInfo(3, 10));

    expect(ServerGameSummary::forEvent($event)[0]['join'])->toMatchArray(['serverId' => $waiting->id]);
});

it('ignores event servers when no event is given', function () {
    $event = Event::factory()->live()->create();
    $game = Game::factory()->create(['name' => 'CS2']);

    summaryLink($event, $game, [], queryInfo(6, 10));

    expect(ServerGameSummary::forEvent(null))->toBe([])
        ->and(ServerGameSummary::totalPlayers(null))->toBe(0)
        ->and(ServerGameSummary::totalPlayers($event))->toBe(6);
});
