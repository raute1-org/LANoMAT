<?php

use App\Modules\Preflight\Checks\DependencyUpdatesCheck;
use App\Modules\Preflight\Contracts\LatestRelease;
use App\Modules\Preflight\Support\DependencyUpdates;
use App\Modules\Preflight\Support\FakeLatestRelease;
use App\Modules\Preflight\Support\HttpLatestRelease;
use App\Modules\Preflight\Support\VersionComparator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

const UPDATES_FIXTURE = 'storage/framework/testing/updates-compose.yml';

/** @param array<string, string|null> $releases */
function watchUpdates(array $releases, string $compose = <<<'YAML'
services:
  mumble:
    image: mumblevoip/mumble-server:v1.5.939
  livekit:
    image: "livekit/livekit-server:v1.13.11"
  tusd:
    image: tusproject/tusd:v2.8
  proxy:
    image: traefik:v3.6
  web:
    image: nginx:1.27-alpine
YAML): FakeLatestRelease
{
    File::ensureDirectoryExists(dirname(base_path(UPDATES_FIXTURE)));
    File::put(base_path(UPDATES_FIXTURE), $compose);

    config(['updates.compose_files' => [UPDATES_FIXTURE, 'does/not/exist.yml']]);
    Cache::flush();

    $fake = new FakeLatestRelease($releases);
    app()->instance(LatestRelease::class, $fake);

    return $fake;
}

afterEach(fn () => File::delete(base_path(UPDATES_FIXTURE)));

it('parses image tags and release names into numeric parts', function (string $tag, ?array $expected) {
    expect(VersionComparator::parse($tag))->toBe($expected);
})->with([
    'v-prefix' => ['v1.13.11', [1, 13, 11]],
    'three-part build number' => ['1.5.939', [1, 5, 939]],
    'alpine suffix' => ['1.27-alpine', [1, 27]],
    'two-part with v' => ['v2.8', [2, 8]],
    'major only' => ['16-alpine', [16]],
    'latest' => ['latest', null],
    'alpine' => ['alpine', null],
    'empty' => ['', null],
]);

it('reports how far a pinned tag trails the latest release', function (string $pinned, string $latest, ?string $behind) {
    expect(VersionComparator::behind($pinned, $latest))->toBe($behind);
})->with([
    'same minor, newer patch' => ['v1.13.11', 'v1.13.14', null],
    'patch-less pin on the latest line' => ['v2.8', 'v2.8.1', null],
    'older minor' => ['v3.5', 'v3.6.2', 'minor'],
    'older major' => ['v1.13.11', 'v2.0.0', 'major'],
    'major wins over minor' => ['1.27-alpine', '2.3.0', 'major'],
    'ahead of upstream' => ['v1.5.939', '1.5.857', null],
    'major only, same major' => ['16-alpine', '16.4', null],
    'non-numeric pin' => ['latest', '1.2.3', null],
    'non-numeric latest' => ['1.2.3', 'nightly', null],
]);

it('is ok when every watched component is on the latest minor line', function () {
    watchUpdates([
        'mumble-voip/mumble' => 'v1.5.939',
        'livekit/livekit' => 'v1.13.14',
        'tus/tusd' => 'v2.8.0',
        'traefik/traefik' => 'v3.6.9',
    ]);
    $this->artisan('lanomat:check-updates')->assertSuccessful();

    $result = app(DependencyUpdatesCheck::class)->run();

    expect($result->status->value)->toBe('ok');
});

it('warns, never goes down, and names the components that trail by a minor or major version', function () {
    watchUpdates([
        'mumble-voip/mumble' => 'v1.6.0',
        'livekit/livekit' => 'v2.0.1',
        'tus/tusd' => 'v2.8.0',
        'traefik/traefik' => 'v3.6.9',
    ]);
    $this->artisan('lanomat:check-updates')->assertSuccessful();

    $result = app(DependencyUpdatesCheck::class)->run();

    expect($result->status->value)->toBe('warn')
        ->and($result->message)->toContain('Mumble v1.5.939 → v1.6.0', 'LiveKit v1.13.11 → v2.0.1')
        ->not->toContain('tusd')->not->toContain('Traefik');
});

it('is skipped while nothing has been looked up', function () {
    watchUpdates([]);

    $result = app(DependencyUpdatesCheck::class)->run();

    expect($result->status->value)->toBe('skipped')
        ->and($result->message)->toBe(__('preflight.messages.updates_unknown'));
});

it('never turns red over a failed lookup and keeps the last good answer', function () {
    $fake = watchUpdates(['mumble-voip/mumble' => 'v1.6.0']);
    $this->artisan('lanomat:check-updates')->assertSuccessful();

    app()->instance(LatestRelease::class, new FakeLatestRelease([]));
    $this->artisan('lanomat:check-updates')
        ->expectsOutputToContain('Lookup failed for')
        ->assertSuccessful();

    $result = app(DependencyUpdatesCheck::class)->run();

    expect($result->status->value)->toBe('warn')
        ->and($result->message)->toContain('Mumble');
});

it('prints a table with pinned and latest versions and skips missing compose files', function () {
    watchUpdates(['mumble-voip/mumble' => 'v1.6.0', 'tus/tusd' => 'v2.8.1']);

    Artisan::call('lanomat:check-updates');

    expect(Artisan::output())->toContain('v1.5.939', 'behind (minor)', 'lookup failed', 'no source');
});

it('caches the looked-up releases so a preflight run never queries upstream', function () {
    $fake = watchUpdates(['mumble-voip/mumble' => 'v1.5.939']);
    $this->artisan('lanomat:check-updates')->assertSuccessful();
    expect($fake->lookups)->toBe(['mumble-voip/mumble', 'livekit/livekit', 'tus/tusd', 'traefik/traefik']);

    app(DependencyUpdatesCheck::class)->run();
    app(DependencyUpdatesCheck::class)->run();

    expect($fake->lookups)->toHaveCount(4)
        ->and(Cache::get('updates.latest.mumble'))->toBe('v1.5.939');

    $this->travel(13)->hours();
    expect(Cache::get('updates.latest.mumble'))->toBeNull();
});

it('asks the GitHub releases API with a short timeout and treats every failure as no answer', function () {
    Http::fake([
        'api.github.com/repos/tus/tusd/releases/latest' => Http::response(['tag_name' => 'v2.9.0']),
        'api.github.com/repos/rate/limited/releases/latest' => Http::response(['message' => 'rate limit'], 403),
        'api.github.com/repos/odd/shape/releases/latest' => Http::response(['name' => 'x']),
        'api.github.com/repos/dead/host/releases/latest' => fn () => throw new ConnectionException('boom'),
    ]);

    $http = new HttpLatestRelease;

    expect($http->latest('tus/tusd'))->toBe('v2.9.0')
        ->and($http->latest('rate/limited'))->toBeNull()
        ->and($http->latest('odd/shape'))->toBeNull()
        ->and($http->latest('dead/host'))->toBeNull();
});

it('binds the HTTP implementation by default and watches the real compose pins', function () {
    expect(app(LatestRelease::class))->toBeInstanceOf(HttpLatestRelease::class);

    config(['updates.compose_files' => ['compose.yml', 'docker/file-host/compose.yml']]);
    $pinned = collect(app(DependencyUpdates::class)->report())->pluck('pinned', 'key');

    expect($pinned->only(['mumble', 'livekit', 'tusd', 'traefik', 'nginx', 'postgres', 'redis'])->filter()->keys()->all())
        ->toBe(['mumble', 'livekit', 'tusd', 'traefik', 'nginx', 'postgres', 'redis']);
});
