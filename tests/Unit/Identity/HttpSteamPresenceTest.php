<?php

declare(strict_types=1);

use App\Modules\Identity\Connectors\HttpSteamPresence;
use App\Modules\Identity\Contracts\SteamPresence;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.steam.client_secret' => 'test-key']);
});

function steamPlayers(array ...$players): array
{
    return ['response' => ['players' => $players]];
}

it('is what the container hands out for the contract', function () {
    expect(app(SteamPresence::class))->toBeInstanceOf(HttpSteamPresence::class);
});

it('reads the game, the game name and the server address of the people in a game', function () {
    Http::fake(['api.steampowered.com/*' => Http::response(steamPlayers(
        ['steamid' => '111', 'personaname' => 'Ada', 'gameid' => '730', 'gameextrainfo' => 'Counter-Strike 2', 'gameserverip' => '198.51.100.7:27015'],
        ['steamid' => '222', 'personaname' => 'Bea'],
        ['steamid' => '333', 'gameid' => '440', 'gameserverip' => '0.0.0.0:0'],
    ))]);

    $result = (new HttpSteamPresence)->activities(['111', '222', '333']);

    expect($result['111']->gameId)->toBe('730')
        ->and($result['111']->gameName)->toBe('Counter-Strike 2')
        ->and($result['111']->serverAddress)->toBe('198.51.100.7:27015')
        ->and($result['111']->personaName)->toBe('Ada')
        ->and($result['222'])->toBeNull()                       // known: not in a game
        ->and($result['333']->serverAddress)->toBeNull();       // the all-zero address means "no server"

    Http::assertSent(fn (Request $request) => $request['key'] === 'test-key' && $request['steamids'] === '111,222,333');
});

it('asks steam for at most 100 ids per request', function () {
    Http::fake(['api.steampowered.com/*' => Http::response(steamPlayers())]);

    $ids = array_map(fn (int $i): string => (string) (76561190000000000 + $i), range(1, 230));

    (new HttpSteamPresence)->activities($ids);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => count(explode(',', $request['steamids'])) <= 100);
});

it('treats a profile steam did not return as unknown, not as idle', function () {
    Http::fake(['api.steampowered.com/*' => Http::response(steamPlayers(['steamid' => '111']))]);

    $result = (new HttpSteamPresence)->activities(['111', '999']);

    expect($result)->toHaveKey('111')->and($result)->not->toHaveKey('999');
});

it('caches an answer for the next call and does not ask again', function () {
    Http::fake(['api.steampowered.com/*' => Http::response(steamPlayers(
        ['steamid' => '111', 'gameid' => '730', 'gameextrainfo' => 'CS2'],
        ['steamid' => '222'],
    ))]);

    $presence = new HttpSteamPresence;
    $presence->activities(['111', '222']);
    $second = $presence->activities(['111', '222']);

    Http::assertSentCount(1);
    expect($second['111']->gameName)->toBe('CS2')->and($second['222'])->toBeNull();
});

it('asks again once the cached answer has expired', function () {
    Http::fake(['api.steampowered.com/*' => Http::response(steamPlayers(['steamid' => '111']))]);

    $presence = new HttpSteamPresence;
    $presence->activities(['111']);

    $this->travel(HttpSteamPresence::TTL_SECONDS + 1)->seconds();
    $presence->activities(['111']);

    Http::assertSentCount(2);
});

it('answers unknown without an api key and never calls steam', function () {
    config(['services.steam.client_secret' => '']);
    Http::fake();

    expect((new HttpSteamPresence)->activities(['111']))->toBe([]);

    Http::assertNothingSent();
});

it('answers unknown on a rate limit and pauses all calls for a while', function () {
    Http::fake(['api.steampowered.com/*' => Http::response('', 429)]);

    $presence = new HttpSteamPresence;

    expect($presence->activities(['111']))->toBe([])
        ->and($presence->activities(['222']))->toBe([]);

    Http::assertSentCount(1); // the second call stayed away: back-off
    expect(Cache::has('steam.presence.backoff'))->toBeTrue();
});

it('answers unknown on a server error and does not cache it as idle', function () {
    Http::fake(['api.steampowered.com/*' => Http::sequence()->push('', 503)->push(steamPlayers(['steamid' => '111', 'gameid' => '730', 'gameextrainfo' => 'CS2']))]);

    $presence = new HttpSteamPresence;
    expect($presence->activities(['111']))->toBe([]);

    Cache::forget('steam.presence.backoff');
    expect($presence->activities(['111'])['111']->gameName)->toBe('CS2');
});

it('answers unknown on a timeout', function () {
    Http::fake(['api.steampowered.com/*' => fn () => throw new ConnectionException('timeout')]);

    expect((new HttpSteamPresence)->activities(['111']))->toBe([]);
});

it('answers unknown on a malformed body', function () {
    Http::fake(['api.steampowered.com/*' => Http::response('<html>nope</html>', 200)]);

    expect((new HttpSteamPresence)->activities(['111']))->toBe([]);
});
