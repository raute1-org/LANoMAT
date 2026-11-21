<?php

declare(strict_types=1);

use App\Modules\Lighting\HttpLightingClient;
use App\Modules\Lighting\Support\FixtureUpdate;
use App\Modules\Lighting\Support\LightState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function lightClient(?string $token = 'secret-token'): HttpLightingClient
{
    return new HttpLightingClient('http://bridge.test:8060/', $token, 2);
}

it('builds the expected requests', function (string $method, string $url, ?array $body, Closure $call) {
    Http::fake(['bridge.test:8060/*' => Http::response(['ok' => true], 200)]);

    $result = $call(lightClient());

    expect($result->ok)->toBeTrue();
    Http::assertSent(function (Request $r) use ($method, $url, $body) {
        return $r->method() === $method
            && $r->url() === $url
            && ($body === null ? $r->body() === '' || $r->body() === '[]' : $r->data() === $body)
            && $r->hasHeader('Authorization', 'Bearer secret-token');
    });
})->with([
    'set fixture' => ['PUT', 'http://bridge.test:8060/api/lights/2', ['hex' => '#ff5520', 'brightness' => 0.8], fn ($c) => $c->setFixture(2, LightState::make('#FF5520', 0.8))],
    'set all' => ['POST', 'http://bridge.test:8060/api/lights/all', ['temperature' => 1.0, 'white_level' => 0.5], fn ($c) => $c->setAll(LightState::make(temperature: 1, whiteLevel: 0.5))],
    'bulk' => ['PUT', 'http://bridge.test:8060/api/lights/bulk', ['items' => [['id' => 1, 'hex' => '#00ff00'], ['id' => 2, 'hex' => '#ff0000']]], fn ($c) => $c->setBulk([new FixtureUpdate(1, LightState::make('#0f0')), new FixtureUpdate(2, LightState::make('#ff0000'))])],
    'pattern' => ['POST', 'http://bridge.test:8060/api/pattern', ['a' => '#00ff00', 'b' => '#ff0000', 'brightness' => 1.0], fn ($c) => $c->pattern('#00FF00', '#ff0000', 5)],
    'blackout' => ['POST', 'http://bridge.test:8060/api/blackout', null, fn ($c) => $c->blackout()],
    'game start' => ['POST', 'http://bridge.test:8060/api/game', ['duration_s' => 5, 'step_ms' => 250], fn ($c) => $c->startGame(5, 250)],
    'game cancel' => ['DELETE', 'http://bridge.test:8060/api/game', null, fn ($c) => $c->cancelGame()],
]);

it('normalises hex colours', function () {
    expect(LightState::normalizeHex('#FF5520'))->toBe('#ff5520')
        ->and(LightState::normalizeHex('f52'))->toBe('#ff5522')
        ->and(LightState::normalizeHex(' #F52 '))->toBe('#ff5522');
})->group('lighting');

it('rejects malformed hex colours', function (string $hex) {
    expect(fn () => LightState::make($hex))->toThrow(InvalidArgumentException::class);
})->with(['zzzzzz', '#12345', '#1234567', 'red', '#ff55 20', '#ff5520; drop']);

it('clamps values and snaps strobe', function () {
    $s = LightState::make('#fff', 3, -1, 0.5, 5);

    expect($s->brightness)->toBe(1.0)->and($s->temperature)->toBe(0.0)->and($s->whiteLevel)->toBe(0.5)->and($s->strobe)->toBe(10);
    expect(LightState::make(strobe: 999)->strobe)->toBe(255)
        ->and(LightState::make(strobe: -4)->strobe)->toBe(0)
        ->and(LightState::make()->isEmpty())->toBeTrue();
    expect(fn () => LightState::make(brightness: NAN))->toThrow(InvalidArgumentException::class);
    expect(fn () => LightState::make(brightness: 'abc'))->toThrow(InvalidArgumentException::class);
});

it('rejects invalid fixture ids without a request', function () {
    expect(lightClient()->setFixture(5, LightState::make('#fff'))->ok)->toBeFalse();
    expect(fn () => new FixtureUpdate(0, new LightState))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('returns unreachable instead of throwing', function () {
    Http::fake(fn () => throw new ConnectionException('refused'));
    $c = lightClient();

    $result = $c->blackout();

    expect($result->ok)->toBeFalse()->and($result->reachable)->toBeFalse();
    expect($c->health()->reachable)->toBeFalse();
    expect($c->fixtures()->reachable)->toBeFalse();
    expect($c->setAll(LightState::make('#fff'))->reachable)->toBeFalse();
});

it('reports a rejecting bridge as reachable but failed', function () {
    Http::fake(['bridge.test:8060/*' => Http::response('no', 500)]);

    $result = lightClient()->blackout();

    expect($result->ok)->toBeFalse()->and($result->reachable)->toBeTrue();
    expect(lightClient()->health()->healthy)->toBeFalse();
});

it('parses health and the light list', function () {
    Http::fake([
        'bridge.test:8060/api/health' => Http::response(['status' => 'ok', 'port' => 'ttyUSB0'], 200),
        'bridge.test:8060/api/lights' => Http::response(['lights' => [
            ['id' => 2, 'hex' => '#00FF00', 'brightness' => 0.5, 'strobe' => 0],
            ['id' => 1, 'hex' => '#ff0000', 'brightness' => 1, 'strobe' => 200],
            ['id' => 99, 'hex' => '#ffffff'],
            ['id' => 3, 'hex' => 'garbage'],
        ]], 200),
    ]);

    $health = lightClient()->health();
    $report = lightClient()->fixtures();

    expect($health->reachable)->toBeTrue()->and($health->healthy)->toBeTrue()->and($health->details['port'])->toBe('ttyUSB0');
    expect($report->reachable)->toBeTrue()
        ->and(array_map(fn ($f) => $f->id, $report->fixtures))->toBe([1, 2, 3])
        ->and($report->fixtures[0]->state->strobe)->toBe(200)
        ->and($report->fixtures[1]->state->hex)->toBe('#00ff00')
        ->and($report->fixtures[2]->state->hex)->toBeNull();
});

it('sends no Authorization header without a token', function () {
    Http::fake(['bridge.test:8060/*' => Http::response([], 200)]);

    lightClient(null)->blackout();

    Http::assertSent(fn (Request $r) => ! $r->hasHeader('Authorization'));
});
