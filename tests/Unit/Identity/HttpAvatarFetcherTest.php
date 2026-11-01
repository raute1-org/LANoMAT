<?php

declare(strict_types=1);

use App\Modules\Identity\Connectors\HttpAvatarFetcher;
use App\Modules\Identity\Exceptions\AvatarFetchFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Feature/Identity/AvatarHelpers.php';

const AVATAR_URL = 'https://cdn.discordapp.com/avatars/1/abc.png';

beforeEach(function () {
    Http::preventStrayRequests();
});

it('downloads an image from the CDN and asks for the stored size', function () {
    $png = avatarImage();
    Http::fake(['cdn.discordapp.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

    $fetched = (new HttpAvatarFetcher)->fetch(AVATAR_URL);

    expect($fetched->binary)->toBe($png)->and($fetched->contentType)->toBe('image/png');
    Http::assertSent(fn (Request $r) => $r->url() === AVATAR_URL.'?size=256');
});

it('follows a redirect inside the allowed hosts', function () {
    Http::fake([
        'cdn.discordapp.com/*' => Http::response('', 302, ['Location' => 'https://media.discordapp.net/avatars/1/abc.png']),
        'media.discordapp.net/*' => Http::response(avatarImage(), 200, ['Content-Type' => 'image/png']),
    ]);

    expect((new HttpAvatarFetcher)->fetch(AVATAR_URL)->contentType)->toBe('image/png');
});

it('refuses a redirect to a foreign host and never requests it', function () {
    Http::fake([
        'cdn.discordapp.com/*' => Http::response('', 302, ['Location' => 'https://evil.example.test/a.png']),
        'evil.example.test/*' => Http::response(avatarImage(), 200, ['Content-Type' => 'image/png']),
    ]);

    expect(fn () => (new HttpAvatarFetcher)->fetch(AVATAR_URL))->toThrow(AvatarFetchFailed::class, 'allowed');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example.test'));
});

it('refuses a redirect that downgrades to http', function () {
    Http::fake(['cdn.discordapp.com/*' => Http::response('', 301, ['Location' => 'http://cdn.discordapp.com/a.png'])]);

    expect(fn () => (new HttpAvatarFetcher)->fetch(AVATAR_URL))->toThrow(AvatarFetchFailed::class);
});

it('gives up after too many redirects', function () {
    Http::fake(['cdn.discordapp.com/*' => Http::response('', 302, ['Location' => '/loop.png'])]);

    expect(fn () => (new HttpAvatarFetcher)->fetch(AVATAR_URL))->toThrow(AvatarFetchFailed::class, 'redirects');
});

it('refuses sources that are not an https Discord CDN host', function (string $url) {
    Http::fake();

    expect(fn () => (new HttpAvatarFetcher)->fetch($url))->toThrow(AvatarFetchFailed::class);
    Http::assertNothingSent();
})->with([
    'plain http' => 'http://cdn.discordapp.com/avatars/1/a.png',
    'other host' => 'https://example.test/a.png',
    'look-alike host' => 'https://cdn.discordapp.com.evil.example.test/a.png',
    'userinfo trick' => 'https://cdn.discordapp.com@evil.example.test/a.png',
    'odd port' => 'https://cdn.discordapp.com:8443/a.png',
    'file scheme' => 'file:///etc/passwd',
]);

it('refuses a response above 2 MB, by header and by body', function () {
    Http::fake(['cdn.discordapp.com/*' => Http::response(str_repeat('a', 2 * 1024 * 1024 + 10), 200, ['Content-Type' => 'image/png'])]);

    expect(fn () => (new HttpAvatarFetcher)->fetch(AVATAR_URL))->toThrow(AvatarFetchFailed::class, 'larger');

    Http::fake(['cdn.discordapp.com/*' => Http::response('tiny', 200, ['Content-Type' => 'image/png', 'Content-Length' => (string) (3 * 1024 * 1024)])]);

    expect(fn () => (new HttpAvatarFetcher)->fetch(AVATAR_URL))->toThrow(AvatarFetchFailed::class, 'larger');
});

it('refuses a non-image content type', function (string $type) {
    Http::fake(['cdn.discordapp.com/*' => Http::response(avatarImage(), 200, ['Content-Type' => $type])]);

    expect(fn () => (new HttpAvatarFetcher)->fetch(AVATAR_URL))->toThrow(AvatarFetchFailed::class, 'content type');
})->with(['text/html', 'image/svg+xml', 'application/octet-stream']);

it('turns error statuses and transport failures into one failure type', function () {
    Http::fake(['cdn.discordapp.com/*' => Http::response('nope', 404)]);
    expect(fn () => (new HttpAvatarFetcher)->fetch(AVATAR_URL))->toThrow(AvatarFetchFailed::class, '404');

    Http::fake(['cdn.discordapp.com/*' => fn () => throw new ConnectionException('timeout')]);
    expect(fn () => (new HttpAvatarFetcher)->fetch(AVATAR_URL))->toThrow(AvatarFetchFailed::class, 'unreachable');
});
