<?php

declare(strict_types=1);

use App\Modules\Lighting\Models\LightingScene;
use App\Modules\Lighting\Support\DefaultScenes;
use App\Modules\Lighting\Support\ScenePlayer;
use App\Modules\Lighting\Testing\FakeLightingClient;
use App\Modules\Preflight\Checks\LightBridgeCheck;
use Database\Seeders\LightingSceneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('seeds the default scenes idempotently and keeps orga edits', function () {
    expect(LightingScene::query()->pluck('key')->sort()->values()->all())
        ->toBe(['calm', 'off', 'party', 'red_alert', 'tournament_final']);

    LightingScene::query()->where('key', 'calm')->update(['name' => 'Meine Ruhe']);
    DefaultScenes::seed();
    (new LightingSceneSeeder)->run();

    expect(LightingScene::query()->count())->toBe(5)
        ->and(LightingScene::query()->where('key', 'calm')->value('name'))->toBe('Meine Ruhe');
});

it('ships scenes that never carry a strobe value and can all be played against the fake', function () {
    $fake = FakeLightingClient::install();

    foreach (LightingScene::all() as $scene) {
        expect(json_encode($scene->states))->not->toContain('"strobe":1')->not->toContain('"strobe":2');
        app(ScenePlayer::class)->play($scene);
    }

    expect($fake->callsTo('blackout'))->toHaveCount(1)
        ->and($fake->callsTo('pattern'))->toHaveCount(2);
});

it('has reversible migrations', function () {
    $migration = require base_path('database/migrations/2026_11_21_150000_create_lighting_tables.php');

    $migration->down();
    expect(Schema::hasTable('lighting_scenes'))->toBeFalse()
        ->and(Schema::hasTable('lighting_rules'))->toBeFalse()
        ->and(Schema::hasTable('lighting_settings'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('lighting_rules'))->toBeTrue()
        ->and(LightingScene::query()->count())->toBe(5);
});

describe('preflight', function () {
    beforeEach(function () {
        Http::preventStrayRequests();
        config(['services.lighting.enabled' => true, 'services.lighting.base_url' => 'http://bridge.test:8060', 'services.lighting.token' => null]);
    });

    it('has the lighting key', function () {
        expect(app(LightBridgeCheck::class)->key())->toBe('lighting');
    });

    it('skips when not configured', function () {
        config(['services.lighting.base_url' => null]);

        expect(app(LightBridgeCheck::class)->run()->status->value)->toBe('skipped');
    });

    it('skips when switched off', function () {
        config(['services.lighting.enabled' => false]);

        expect(app(LightBridgeCheck::class)->run()->status->value)->toBe('skipped');
    });

    it('is ok on a healthy bridge', function () {
        Http::fake(['bridge.test:8060/api/health' => Http::response(['ok' => true, 'error' => null, 'fixtures' => 2], 200)]);

        expect(app(LightBridgeCheck::class)->run()->status->value)->toBe('ok');
    });

    it('warns when the bridge reports an error', function () {
        Http::fake(['bridge.test:8060/api/health' => Http::response(['ok' => false, 'error' => 'no adapter'], 200)]);

        expect(app(LightBridgeCheck::class)->run()->status->value)->toBe('warn');
    });

    it('warns on an http error and when unreachable', function () {
        Http::fake(['bridge.test:8060/api/health' => Http::response('x', 500)]);
        expect(app(LightBridgeCheck::class)->run()->status->value)->toBe('warn');

        Http::fake(fn () => throw new ConnectionException('refused'));
        expect(app(LightBridgeCheck::class)->run()->status->value)->toBe('warn');
    });
});

it('prints the status and plays scenes from the console', function () {
    $fake = FakeLightingClient::install()->withTopology(2, 4);

    expect(Artisan::call('lighting:status'))->toBe(0);
    expect(Artisan::output())->toContain('Bridge: ok');

    expect(Artisan::call('lighting:scene', ['name' => 'Rot-Alarm']))->toBe(0);
    expect($fake->callsTo('setAll')[0][1]->hex)->toBe('#ff0000');

    expect(Artisan::call('lighting:scene', ['name' => 'does-not-exist']))->toBe(1);
});

it('fails the status command quietly when the bridge is unreachable', function () {
    FakeLightingClient::install()->unreachable = true;

    expect(Artisan::call('lighting:status'))->toBe(1);
});
