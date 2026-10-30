<?php

use App\Modules\GameServers\Support\SourceQueryParser;
use App\Modules\GameServers\Support\UdpServerQuery;

function a2sInfoPacket(string $name = 'LANoMAT TTT', string $map = 'gm_construct', string $folder = 'garrysmod', string $game = 'Trouble in Terrorist Town', int $players = 3, int $max = 16, int $bots = 1): string
{
    return "\xff\xff\xff\xff\x49\x11".$name."\0".$map."\0".$folder."\0".$game."\0".pack('v', 4000).chr($players).chr($max).chr($bots).'d'.'l'."\0"."\0"."1.0.0\0";
}

it('parses an A2S_INFO answer into name, map, folder, game and players', function () {
    $info = SourceQueryParser::parseInfo(a2sInfoPacket());

    expect($info->name)->toBe('LANoMAT TTT')
        ->and($info->map)->toBe('gm_construct')
        ->and($info->folder)->toBe('garrysmod')
        ->and($info->game)->toBe('Trouble in Terrorist Town')
        ->and($info->players)->toBe(3)
        ->and($info->maxPlayers)->toBe(16)
        ->and($info->bots)->toBe(1);
});

it('recognises a challenge and extracts its four bytes', function () {
    expect(SourceQueryParser::challenge("\xff\xff\xff\xff\x41\x01\x02\x03\x04"))->toBe("\x01\x02\x03\x04")
        ->and(SourceQueryParser::challenge(a2sInfoPacket()))->toBeNull();
});

it('rejects packets that are not a complete info answer', function (string $packet) {
    expect(SourceQueryParser::parseInfo($packet))->toBeNull();
})->with([
    'empty' => [''],
    'wrong header' => ["\x00\x00\x00\x00\x49\x11a\0b\0c\0d\0"],
    'wrong type' => ["\xff\xff\xff\xff\x44\x11a\0b\0c\0d\0\x00\x00\x01\x02\x00"],
    'truncated strings' => ["\xff\xff\xff\xff\x49\x11name"],
    'truncated numbers' => ["\xff\xff\xff\xff\x49\x11a\0b\0c\0d\0\x01"],
]);

/**
 * Starts a throwaway UDP "game server" on loopback in a child php process that
 * answers one query (optionally via a challenge round) and exits.
 *
 * @return array{process: resource, port: int}
 */
function startUdpFixtureServer(bool $challenge): array
{
    $info = base64_encode(a2sInfoPacket());
    $script = <<<'PHP'
$s = stream_socket_server('udp://127.0.0.1:0', $e, $m, STREAM_SERVER_BIND);
fwrite(STDOUT, explode(':', stream_socket_get_name($s, false))[1]."\n"); fflush(STDOUT);
$info = base64_decode($argv[1]); $challenge = $argv[2] === '1';
$deadline = time() + 10;
while (time() < $deadline) {
    $r = [$s]; $w = $x = null;
    if (stream_select($r, $w, $x, 1) === 0) { continue; }
    $data = stream_socket_recvfrom($s, 1500, 0, $peer);
    $isChallenged = strlen($data) > 25;
    if ($challenge && ! $isChallenged) { stream_socket_sendto($s, "\xff\xff\xff\xff\x41\x0a\x0b\x0c\x0d", 0, $peer); continue; }
    stream_socket_sendto($s, $info, 0, $peer);
    break;
}
PHP;
    $process = proc_open([PHP_BINARY, '-r', $script, '--', $info, $challenge ? '1' : '0'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $port = (int) trim((string) fgets($pipes[1]));

    return ['process' => $process, 'port' => $port];
}

it('queries a live UDP server and follows a challenge', function (bool $challenge) {
    ['process' => $process, 'port' => $port] = startUdpFixtureServer($challenge);

    $info = (new UdpServerQuery(timeoutSeconds: 3.0))->info('127.0.0.1', $port);

    proc_close($process);

    expect($info)->not->toBeNull()
        ->and($info->name)->toBe('LANoMAT TTT')
        ->and($info->players)->toBe(3);
})->with([[false], [true]]);

it('returns null when nothing answers within the timeout', function () {
    $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
    $port = (int) explode(':', (string) stream_socket_get_name($socket, false))[1];

    expect((new UdpServerQuery(timeoutSeconds: 0.3))->info('127.0.0.1', $port))->toBeNull();
});
