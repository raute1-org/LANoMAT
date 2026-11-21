<?php

declare(strict_types=1);

use App\Modules\GameRecipes\Support\PublicSafeText;

/**
 * The gate in front of the public git catalogue: a recipe written on the LAN
 * must not carry the orga's network, a personal address, a token or a key into
 * the repository. Findings never repeat the value in full.
 */
function publicSafeText(): PublicSafeText
{
    return new PublicSafeText;
}

it('accepts ordinary recipe text', function () {
    $text = <<<'YAML'
schema: lanomat.recipe/v1
slug: tiny-racer
title: Tiny Racer
version: 1.2.3.4
homepage: https://example.com/tiny-racer
summary: Unpack tiny-racer-1.2.3.4.zip and start the game. Join at lan.example:27015.
YAML;

    expect(publicSafeText()->problems($text))->toBe([]);
});

it('accepts documentation, loopback and special-use addresses', function (string $address) {
    expect(publicSafeText()->problems('Connect to '.$address.' for the test run.'))->toBe([]);
})->with([
    'documentation' => ['203.0.113.9'],
    'documentation second range' => ['198.51.100.4'],
    'documentation third range' => ['192.0.2.10'],
    'loopback' => ['127.0.0.1'],
    'ipv6 loopback' => ['::1'],
    'link local' => ['169.254.10.20'],
    'public resolver' => ['1.1.1.1'],
]);

it('refuses private and public addresses and redacts the value', function (string $address, string $class) {
    $problems = publicSafeText()->problems("run: curl http://{$address}/status");

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain($class)
        ->and($problems[0])->not->toContain($address)
        ->and($problems[0])->toMatch('/'.preg_quote(substr($address, 0, 2), '/').'\[REDACTED len=\d+\]/');
})->with([
    'private ten' => ['10.20.30.40', 'a private IPv4 address'],
    'private 172' => ['172.20.0.5', 'a private IPv4 address'],
    'private 192' => ['192.168.10.10', 'a private IPv4 address'],
    'public' => ['93.184.216.34', 'a public IPv4 address'],
]);

it('refuses a URL whose host is a bare documentation address', function () {
    $problems = publicSafeText()->problems('Join: https://203.0.113.9:8080/game');

    expect(collect($problems)->contains(fn (string $problem): bool => str_contains($problem, 'URL whose host is a bare IP address')))->toBeTrue();
});

it('refuses e-mail addresses that are not documentation addresses', function () {
    expect(publicSafeText()->problems('asked@example.com'))->toBe([])
        ->and(publicSafeText()->problems('someone@example.org'))->toBe([])
        ->and(publicSafeText()->problems('first.last@lanomat.local'))->toBe([])
        ->and(publicSafeText()->problems('someone@private-domain.test'))->toHaveCount(1);
});

it('refuses key material', function () {
    $privateKey = "-----BEGIN RSA PRIVATE KEY-----\nMIIEow...\n-----END RSA PRIVATE KEY-----";
    $publicKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExampleKeyMaterialHere000000000000000000 lanomat';

    expect(publicSafeText()->problems($privateKey))->toHaveCount(1)
        ->and(publicSafeText()->problems($publicKey))->toHaveCount(1);
});

it('refuses the token prefixes LANoMAT and GitHub hand out', function (string $token) {
    $problems = publicSafeText()->problems('token: '.$token);

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('an access token')
        ->and($problems[0])->not->toContain($token);
})->with([
    'mcp' => ['lmcp_abcdefgh1234'],
    'voice client' => ['lvc_abcdefgh1234'],
    'classic personal' => ['ghp_abcdefghijklmnopqrstuvwxyz012345'],
    'fine grained' => ['github_pat_11ABCDEFGHI0123456789'],
    'oauth' => ['gho_abcdefghijklmnopqrstuvwxyz012345'],
]);

it('refuses IPv6 literals that are not the loopback', function () {
    expect(publicSafeText()->problems('server fe80::1 please'))->toHaveCount(1)
        ->and(publicSafeText()->problems('server 2001:db8:0:0:0:0:0:1 please'))->toHaveCount(1);
});

it('does not mistake a clock time, a MAC address or a Windows path for an address', function () {
    expect(publicSafeText()->problems('starts at 12:30:45 sharp'))->toBe([])
        ->and(publicSafeText()->problems('nic aa:bb:cc:dd:ee:ff'))->toBe([])
        ->and(publicSafeText()->problems('C:\Games\TinyRacer\tiny-racer.exe'))->toBe([]);
});

it('names the line so a blocked recipe can be fixed', function () {
    $problems = publicSafeText()->problems("title: Tiny Racer\nrun: ping 10.0.0.5\n");

    expect($problems[0])->toStartWith('line 2:');
});
