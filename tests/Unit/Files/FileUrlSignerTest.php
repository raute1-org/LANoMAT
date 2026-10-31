<?php

use App\Modules\Files\Support\FileUrlSigner;

const SIGNER_SECRET = 'abcdefghijklmnopqrstuvwxyzABCDEF_-0123456789';

it('matches the documented nginx secure_link_md5 recipe (pinned openssl vector)', function () {
    // Generated with the nginx docs pipeline, not with PHP:
    //   echo -n '1893456000/dl/event-3/<uuid>|My%20Mod%20Pack.zip <secret>' \
    //     | openssl md5 -binary | openssl base64 | tr +/ -_ | tr -d =
    $signer = new FileUrlSigner('https://files.example.test', SIGNER_SECRET, 300);

    expect($signer->signature(1893456000, '/dl/event-3/0b7e1c9a-1111-4222-8333-444455556666', 'My%20Mod%20Pack.zip'))
        ->toBe('0NEOa4m1H5kggWTICxB0Qg');
});

it('builds a link under /dl/ with expiry, signature and a percent-encoded name', function () {
    $signer = new FileUrlSigner('https://files.example.test/', SIGNER_SECRET, 300);

    $url = $signer->url('event-3/0b7e1c9a-1111-4222-8333-444455556666', 'My Mod Pack.zip', 1893455700);

    expect($url)->toBe('https://files.example.test/dl/event-3/0b7e1c9a-1111-4222-8333-444455556666'
        .'?expires=1893456000&md5=0NEOa4m1H5kggWTICxB0Qg&n=My%20Mod%20Pack.zip');
});

it('never puts the secret in the link', function () {
    $signer = new FileUrlSigner('https://files.example.test', SIGNER_SECRET, 300);

    expect($signer->url('event-1/x', 'a.zip'))->not->toContain(SIGNER_SECRET);
});

it('changes the signature when the expiry, the path or the file name change', function () {
    $signer = new FileUrlSigner('https://files.example.test', SIGNER_SECRET, 300);
    $base = $signer->signature(100, '/dl/event-1/a', 'a.zip');

    expect($signer->signature(101, '/dl/event-1/a', 'a.zip'))->not->toBe($base)
        ->and($signer->signature(100, '/dl/event-1/b', 'a.zip'))->not->toBe($base)
        ->and($signer->signature(100, '/dl/event-1/a', 'b.zip'))->not->toBe($base)
        ->and((new FileUrlSigner('https://x', 'another-secret-another-secret-another', 300))->signature(100, '/dl/event-1/a', 'a.zip'))->not->toBe($base);
});

it('encodes hostile file names so they cannot inject headers or query parameters', function () {
    $signer = new FileUrlSigner('https://files.example.test', SIGNER_SECRET, 300);

    $url = $signer->url('event-1/x', "evil\r\nSet-Cookie: a=b&md5=1.zip", 1);

    expect(preg_match('/[\r\n ]/', $url))->toBe(0)
        ->and(substr_count($url, '&md5='))->toBe(1);
});

it('accepts only long secrets made of nginx-safe characters', function (string $secret, bool $ok) {
    expect(FileUrlSigner::isAcceptableSecret($secret))->toBe($ok);
})->with([
    'good' => [SIGNER_SECRET, true],
    'hex 64' => [str_repeat('ab12', 16), true],
    'too short' => ['short-secret', false],
    'empty' => ['', false],
    'dollar' => [str_repeat('a', 40).'$x', false],
    'quote' => [str_repeat('a', 40).'"', false],
    'space' => [str_repeat('a', 40).' b', false],
]);
