<?php

use App\Modules\GameServers\Support\UdpUt2004Query;
use App\Modules\GameServers\Support\Ut2004Protocol;

function ut2004Answer(string $name = 'Raute1 LAN', int $players = 2, int $max = 16): string
{
    $string = static fn (string $s): string => chr(strlen($s) + 1).$s."\x00";

    // header, type, server id, empty IP string, game port 7777, query port, then the strings (layout verified against a real server)
    return hex2bin('8000000000')
        .pack('V', 0)."\x00".pack('V', 7777).pack('V', 0)
        .$string($name).$string('DM-DE-GRENDELKEEP').$string('xDeathMatch')
        .pack('V4', $players, $max, 0, 0);
}

it('builds the five byte info request', function () {
    expect(bin2hex(Ut2004Protocol::infoRequest()))->toBe('8000000000');
});

it('derives the query port from the game port in one place', function () {
    expect(Ut2004Protocol::queryPort(7777))->toBe(7778);
});

it('parses the real basic info answer', function () {
    $info = Ut2004Protocol::parseInfo(ut2004Answer());

    expect($info)->not->toBeNull()
        ->and($info->name)->toBe('Raute1 LAN')
        ->and($info->map)->toBe('DM-DE-GRENDELKEEP')
        ->and($info->game)->toBe('xDeathMatch')
        ->and($info->players)->toBe(2)
        ->and($info->maxPlayers)->toBe(16)
        ->and($info->bots)->toBe(0)
        ->and($info->playerNames)->toBe([])
        ->and($info->toArray())->not->toHaveKey('player_names');
});

it('parses a real answer captured from a UT2004 server', function () {
    $real = hex2bin('80000000000000000000611e0000000000000b526175746531204c414e000d444d2d44452d49524f4e4943000c7844656174684d6174636800010000001000000000000000000000000230000000');

    $info = Ut2004Protocol::parseInfo($real);

    expect($info)->not->toBeNull()
        ->and($info->name)->toBe('Raute1 LAN')
        ->and($info->map)->toBe('DM-DE-IRONIC')
        ->and($info->game)->toBe('xDeathMatch')
        ->and($info->players)->toBe(1)
        ->and($info->maxPlayers)->toBe(16);
});

it('strips colour codes and control bytes from the name', function () {
    $info = Ut2004Protocol::parseInfo(ut2004Answer("\x1b\xff\x00\x00Raute1\x1b\x00\xff\x00 LAN"));

    expect($info->name)->toBe('Raute1 LAN');
});

it('rejects a truncated answer at every length', function () {
    $full = ut2004Answer();

    // Everything shorter than header + three strings + players/max is invalid.
    for ($length = 0; $length < strlen($full) - 8; $length++) {
        expect(Ut2004Protocol::parseInfo(substr($full, 0, $length)))->toBeNull("length {$length}");
    }
});

it('rejects a wrong header or type', function () {
    $full = ut2004Answer();

    expect(Ut2004Protocol::parseInfo('X'.substr($full, 1)))->toBeNull()
        ->and(Ut2004Protocol::parseInfo(substr($full, 0, 4)."\x02".substr($full, 5)))->toBeNull()
        ->and(Ut2004Protocol::parseInfo(''))->toBeNull();
});

it('rejects an oversized or unterminated string length', function () {
    $full = ut2004Answer();

    // First string length byte sits at offset 18 (header, type, id, empty IP string, two ports).
    expect(Ut2004Protocol::parseInfo(substr($full, 0, 18)."\x7f".substr($full, 19)))->toBeNull()
        ->and(Ut2004Protocol::parseInfo(substr($full, 0, 18)."\xff".substr($full, 19)))->toBeNull()
        ->and(Ut2004Protocol::parseInfo(substr($full, 0, 18)."\x0bRaute1 LANX".substr($full, 30)))->toBeNull();
});

it('rejects player counts outside 0 to 256', function () {
    expect(Ut2004Protocol::parseInfo(ut2004Answer(players: 257)))->toBeNull()
        ->and(Ut2004Protocol::parseInfo(ut2004Answer(max: 257)))->toBeNull()
        ->and(Ut2004Protocol::parseInfo(ut2004Answer(players: 0xFFFFFFFF)))->toBeNull()
        ->and(Ut2004Protocol::parseInfo(ut2004Answer(players: 256, max: 256)))->not->toBeNull();
});

it('returns null for unusable hosts and ports without touching the network', function () {
    $query = new UdpUt2004Query(0.1);

    expect($query->info('', 7777))->toBeNull()
        ->and($query->info('203.0.113.10', 0))->toBeNull()
        ->and($query->info('203.0.113.10', 65535))->toBeNull();
});

function ut2004Players(): string
{
    $name = static fn (string $s): string => chr(strlen($s) + 1).$s."\x00";

    // No count byte: the entries follow the type byte (layout verified against a real server).
    return hex2bin('8000000002')
        .pack('V', 2).$name('Kleintonno').pack('l3', 28, 2, 0)
        .pack('V', 3).$name("\x1b\x10\x20\x30klaus").pack('l3', 28, 17, 0);
}

it('parses the player list, strips colour codes and sorts by score', function () {
    $players = Ut2004Protocol::parsePlayers(ut2004Players());

    expect($players)->toBe([
        ['name' => 'klaus', 'score' => 17, 'seconds' => null],
        ['name' => 'Kleintonno', 'score' => 2, 'seconds' => null],
    ]);
});

it('parses a real player answer captured from a UT2004 server', function () {
    $real = hex2bin('800000000202000000066b6c617573001c0000000100000000000000');

    expect(Ut2004Protocol::parsePlayers($real))->toBe([['name' => 'klaus', 'score' => 1, 'seconds' => null]]);
});

it('keeps what was read when the player answer is cut off, and never throws', function () {
    $full = ut2004Players();

    for ($length = 0; $length < strlen($full); $length++) {
        $result = Ut2004Protocol::parsePlayers(substr($full, 0, $length));
        expect($result === null || is_array($result))->toBeTrue();
    }

    expect(Ut2004Protocol::parsePlayers(substr($full, 0, 5 + 4 + 12 + 12 + 6)))->toHaveCount(1)
        ->and(Ut2004Protocol::parsePlayers('garbage'))->toBeNull()
        ->and(Ut2004Protocol::parsePlayers(hex2bin('8000000002').pack('V', 0)."\xff"))->toBe([]);
});
