<?php

use App\Modules\GameServers\Support\MinecraftPing;
use App\Modules\GameServers\Support\TcpMinecraftQuery;

/** A status response packet as a Minecraft server sends it. */
function mcResponse(array|string $status): string
{
    $json = is_string($status) ? $status : json_encode($status, JSON_THROW_ON_ERROR);
    $payload = MinecraftPing::varInt(0).MinecraftPing::varInt(strlen($json)).$json;

    return MinecraftPing::varInt(strlen($payload)).$payload;
}

it('encodes and decodes var ints', function (int $value, string $hex) {
    expect(bin2hex(MinecraftPing::varInt($value)))->toBe($hex);

    $offset = 0;
    expect(MinecraftPing::readVarInt(hex2bin($hex), $offset))->toBe($value & 0xFFFFFFFF)
        ->and($offset)->toBe(strlen($hex) / 2);
})->with([
    [0, '00'],
    [1, '01'],
    [127, '7f'],
    [128, '8001'],
    [25565, 'ddc701'],
    [0xFFFFFFFF, 'ffffffff0f'],
]);

it('returns null for a truncated var int', function () {
    $offset = 0;

    expect(MinecraftPing::readVarInt("\x80", $offset))->toBeNull();
});

it('builds the handshake (next state status) followed by the status request', function () {
    $request = MinecraftPing::request('mc.example.test', 25565);

    $payload = MinecraftPing::varInt(0)
        .MinecraftPing::varInt(0xFFFFFFFF)
        .MinecraftPing::varInt(15).'mc.example.test'
        .pack('n', 25565)
        .MinecraftPing::varInt(1);

    expect($request)->toBe(MinecraftPing::varInt(strlen($payload)).$payload."\x01\x00");
});

it('parses players, version and a plain motd', function () {
    $info = MinecraftPing::parse(mcResponse([
        'version' => ['name' => 'Paper 1.21.4', 'protocol' => 769],
        'players' => ['max' => 20, 'online' => 3],
        'description' => 'LAN Survival',
    ]));

    expect($info)->not->toBeNull()
        ->and($info->players)->toBe(3)
        ->and($info->maxPlayers)->toBe(20)
        ->and($info->game)->toBe('Paper 1.21.4')
        ->and($info->name)->toBe('LAN Survival')
        ->and($info->map)->toBe('');
});

it('flattens a chat component motd and strips formatting codes', function () {
    $info = MinecraftPing::parse(mcResponse([
        'version' => ['name' => '1.21'],
        'players' => ['max' => 10, 'online' => 0],
        'description' => ['text' => '§aRaute1 ', 'extra' => [['text' => 'Survival'], ['text' => ' §lLive']]],
    ]));

    expect($info->name)->toBe('Raute1 Survival Live');
});

it('rejects garbage, a wrong packet id and incomplete packets', function (string $data) {
    expect(MinecraftPing::parse($data))->toBeNull();
})->with([
    'empty' => [''],
    'noise' => ["\xff\xff\xff\xff\xff\xff"],
    'not json' => [fn () => mcResponse('not json at all')],
    'json without players' => [fn () => mcResponse('{"version":{"name":"x"}}')],
    'truncated' => [fn () => substr(mcResponse(['players' => ['max' => 1, 'online' => 0]]), 0, 10)],
]);

it('reports the full length of an answer once the prefix is complete', function () {
    $packet = mcResponse(['players' => ['max' => 1, 'online' => 0]]);

    expect(MinecraftPing::expectedLength(substr($packet, 0, 1)))->toBe(strlen($packet))
        ->and(MinecraftPing::expectedLength(''))->toBeNull();
});

it('does not query nonsense targets over the network', function () {
    $query = new TcpMinecraftQuery(0.2);

    expect($query->info('', 25565))->toBeNull()
        ->and($query->info('127.0.0.1', 0))->toBeNull();
});
