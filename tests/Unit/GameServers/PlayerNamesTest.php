<?php

use App\Modules\GameServers\Domain\ServerInfo;
use App\Modules\GameServers\Support\MinecraftPing;

/** A status response packet as a Minecraft server sends it. */
function pnStatus(array $status): string
{
    $json = json_encode($status, JSON_THROW_ON_ERROR);
    $payload = MinecraftPing::varInt(0).MinecraftPing::varInt(strlen($json)).$json;

    return MinecraftPing::varInt(strlen($payload)).$payload;
}

it('reads the player names of the status sample', function () {
    $info = MinecraftPing::parse(pnStatus([
        'version' => ['name' => '1.21'],
        'players' => ['online' => 3, 'max' => 20, 'sample' => [
            ['name' => 'Ada', 'id' => '11111111-1111-1111-1111-111111111111'],
            ['name' => '§aBea', 'id' => '22222222-2222-2222-2222-222222222222'],
        ]],
        'description' => 'LAN',
    ]));

    expect($info->players)->toBe(3)->and($info->playerNames)->toBe(['Ada', 'Bea']);
});

it('skips decoy and malformed sample entries and copes with no sample at all', function () {
    $info = MinecraftPing::parse(pnStatus([
        'players' => ['online' => 1, 'max' => 5, 'sample' => [
            ['name' => 'Anonymous Player', 'id' => '00000000-0000-0000-0000-000000000000'],
            ['name' => '', 'id' => 'x'],
            ['id' => 'no-name'],
            'garbage',
            ['name' => 'Cem', 'id' => '33333333-3333-3333-3333-333333333333'],
        ]],
    ]));

    expect($info->playerNames)->toBe(['Cem']);

    expect(MinecraftPing::parse(pnStatus(['players' => ['online' => 0, 'max' => 5]]))->playerNames)->toBe([])
        ->and(MinecraftPing::parse(pnStatus(['players' => ['online' => 0, 'max' => 5, 'sample' => 'nope']]))->playerNames)->toBe([]);
});

it('stores player names in the query info only when there are some, and reads them back', function () {
    $without = new ServerInfo('S', 'map', 'garrysmod', 'TTT', 2, 16);
    expect($without->toArray())->not->toHaveKey('player_names');

    $with = new ServerInfo('S', '', 'minecraft', '1.21', 2, 20, 0, ['Ada', 'Bea']);
    expect($with->toArray()['player_names'])->toBe(['Ada', 'Bea'])
        ->and(ServerInfo::fromArray($with->toArray())->playerNames)->toBe(['Ada', 'Bea'])
        ->and(ServerInfo::fromArray($without->toArray())->playerNames)->toBe([]);
});
