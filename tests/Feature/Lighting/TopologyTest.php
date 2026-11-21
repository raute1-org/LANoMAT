<?php

declare(strict_types=1);

use App\Modules\Lighting\HttpLightingClient;
use App\Modules\Lighting\Support\TopologyResolver;
use App\Modules\Lighting\Testing\FakeLightingClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Cache::flush());

it('reads count and channels from the bridge health', function () {
    Http::fake(['bridge.test/api/health' => Http::response([
        'ok' => true, 'port' => '/dev/ttyUSB0', 'error' => null, 'fixtures' => 2, 'channels_per_fixture' => 4, 'start_addresses' => [1, 7],
    ], 200)]);

    $health = (new HttpLightingClient('http://bridge.test'))->health();

    expect($health->fixtureCount)->toBe(2)->and($health->channelsPerFixture)->toBe(4)->and($health->startAddresses)->toBe([1, 7])->and($health->healthy)->toBeTrue();
});

it('derives capabilities from the channel count', function () {
    FakeLightingClient::install()->withTopology(2, 4);
    $t = app(TopologyResolver::class)->resolve();
    expect($t->ids())->toBe([1, 2])->and($t->supportsStrobe())->toBeFalse()->and($t->supportsBrightness())->toBeFalse();

    Cache::flush();
    FakeLightingClient::install()->withTopology(4, 6);
    $t = app(TopologyResolver::class)->resolve();
    expect($t->ids())->toBe([1, 2, 3, 4])->and($t->supportsStrobe())->toBeTrue()->and($t->supportsWhite())->toBeTrue();
});

it('falls back to 1..4 and colour only when health is unreadable', function () {
    FakeLightingClient::install()->unreachable = true;

    $t = app(TopologyResolver::class)->resolve();

    expect($t->ids())->toBe([1, 2, 3, 4])->and($t->known)->toBeFalse()->and($t->supportsStrobe())->toBeFalse();
});
