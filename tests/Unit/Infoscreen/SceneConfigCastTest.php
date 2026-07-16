<?php

use App\Modules\Infoscreen\Casts\SceneConfigCast;
use App\Modules\Infoscreen\Domain\SceneConfig;
use App\Modules\Infoscreen\Models\InfoscreenScene;

it('round-trips a SceneConfig through set and get', function () {
    $scene = InfoscreenScene::factory()->create([
        'config' => new SceneConfig(
            headline: 'Willkommen',
            body: 'Viel Spaß auf der LAN!',
        ),
    ]);

    $fresh = $scene->fresh();

    expect($fresh->config)->toBeInstanceOf(SceneConfig::class)
        ->and($fresh->config->headline)->toBe('Willkommen')
        ->and($fresh->config->body)->toBe('Viel Spaß auf der LAN!');
});

it('decodes null config to an empty SceneConfig', function () {
    $scene = InfoscreenScene::factory()->create(['config' => null]);

    expect($scene->fresh()->config)->toBeInstanceOf(SceneConfig::class)
        ->and($scene->fresh()->config->toArray())->toBe([]);
});

it('decodes an empty array config to an empty SceneConfig', function () {
    $scene = InfoscreenScene::factory()->create(['config' => []]);

    expect($scene->fresh()->config)->toBeInstanceOf(SceneConfig::class)
        ->and($scene->fresh()->config->toArray())->toBe([]);
});

it('ignores unknown keys when decoding', function () {
    $cast = new SceneConfigCast;
    $model = new InfoscreenScene;

    $config = $cast->get($model, 'config', json_encode([
        'headline' => 'Hallo',
        'totally_unknown_key' => 'should be ignored',
    ]), []);

    expect($config)->toBeInstanceOf(SceneConfig::class)
        ->and($config->headline)->toBe('Hallo');
});

it('throws for a non-array, non-SceneConfig value on set', function () {
    $cast = new SceneConfigCast;
    $model = new InfoscreenScene;

    $cast->set($model, 'config', 'not-an-array', []);
})->throws(InvalidArgumentException::class, 'SceneConfig cast expects an array or SceneConfig for [config].');

it('serializes sponsorLogoPaths and drops nulls/empties on set', function () {
    $scene = InfoscreenScene::factory()->create([
        'config' => new SceneConfig(
            headline: 'Sponsoren',
            sponsorLogoPaths: ['logos/a.png', 'logos/b.png'],
        ),
    ]);

    $fresh = $scene->fresh();

    expect($fresh->config->sponsorLogoPaths)->toBe(['logos/a.png', 'logos/b.png'])
        ->and($fresh->config->body)->toBeNull();
});
