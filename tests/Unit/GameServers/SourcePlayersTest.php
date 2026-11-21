<?php

use App\Modules\GameServers\Support\PlayerList;
use App\Modules\GameServers\Support\SourceQueryParser;

function a2sPlayers(array $rows): string
{
    $out = "\xff\xff\xff\xff\x44".chr(count($rows));

    foreach ($rows as $i => [$name, $score, $seconds]) {
        $out .= chr($i).$name."\x00".pack('l', $score).pack('g', $seconds);
    }

    return $out;
}

it('parses an A2S_PLAYER answer, skipping empty names', function () {
    $players = SourceQueryParser::parsePlayers(a2sPlayers([['Anna', 5, 125.7], ['', 0, 3.0], ['Bob', 12, 3700.0]]));

    expect($players)->toBe([
        ['name' => 'Bob', 'score' => 12, 'seconds' => 3700],
        ['name' => 'Anna', 'score' => 5, 'seconds' => 125],
    ]);
});

it('reads the challenge answer for the player request', function () {
    expect(SourceQueryParser::challenge("\xff\xff\xff\xff\x41\x01\x02\x03\x04"))->toBe("\x01\x02\x03\x04");
});

it('handles malformed A2S_PLAYER answers without throwing', function () {
    $full = a2sPlayers([['Anna', 5, 1.0], ['Bob', 6, 2.0]]);

    for ($length = 0; $length < strlen($full); $length++) {
        $result = SourceQueryParser::parsePlayers(substr($full, 0, $length));
        expect($result === null || is_array($result))->toBeTrue();
    }

    expect(SourceQueryParser::parsePlayers("\xff\xff\xff\xff\x49abc"))->toBeNull()
        ->and(SourceQueryParser::parsePlayers(a2sPlayers([['Anna', 1, NAN]])))->toBe([['name' => 'Anna', 'score' => 1, 'seconds' => null]]);
});

it('sanitises names: markup, control characters, length, invalid UTF-8 and the entry cap', function () {
    expect(PlayerList::name("<b>Ev\x07il</b><script>x</script>"))->toBe('Evilx')
        ->and(PlayerList::name(str_repeat('ä', 50)))->toBe(str_repeat('ä', 32))
        ->and(PlayerList::name("bad\xc3\x28"))->toBeString()
        ->and(PlayerList::name('a &lt;b&gt; c'))->toBe('a b c');

    $many = array_map(fn (int $i): array => ['name' => "p{$i}", 'score' => $i, 'seconds' => null], range(1, 100));

    expect(PlayerList::normalize($many))->toHaveCount(64)
        ->and(PlayerList::normalize($many)[0]['score'])->toBe(100)
        ->and(PlayerList::normalize(['x', 5, ['name' => 7]]))->toBe([]);
});
