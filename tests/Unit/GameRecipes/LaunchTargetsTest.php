<?php

declare(strict_types=1);

use App\Modules\GameRecipes\Models\GameRecipe;

/**
 * The canonical launch contract (docs-site/deploy/merge-log.md § 10):
 * `metadata.launch` is a string or `{kind, target, args?}`; `metadata.launch_targets`
 * is the multi-option form and wins; `metadata.play_url` may contain `{{host}}`.
 */
function recipeWithMetadata(array $metadata): GameRecipe
{
    $recipe = new GameRecipe;
    $recipe->metadata = $metadata;

    return $recipe;
}

it('derives one target from a plain string launch command', function () {
    $recipe = recipeWithMetadata(['launch' => 'C:\\Games\\UT2004\\System\\UT2004.exe']);

    expect($recipe->launchTargets())->toBe([[
        'id' => 'default',
        'label' => 'Start',
        'os' => 'any',
        'command' => 'C:\\Games\\UT2004\\System\\UT2004.exe',
        'shell' => null,
        'notes' => null,
    ]]);
    expect($recipe->launchTarget())->toBe('C:\\Games\\UT2004\\System\\UT2004.exe');
});

it('flattens the exe object form with arguments', function () {
    $recipe = recipeWithMetadata([
        'launch' => ['kind' => 'exe', 'target' => 'C:\\Games\\UT2004\\System\\UT2004.exe', 'args' => ['-nosplash', '-window']],
    ]);

    expect($recipe->launchTarget())->toBe('C:\\Games\\UT2004\\System\\UT2004.exe -nosplash -window');
});

it('turns a steam app id into a steam URI and keeps uri targets as they are', function () {
    $steam = recipeWithMetadata(['launch' => ['kind' => 'steam', 'target' => '13210', 'args' => '-novid']]);
    $uri = recipeWithMetadata(['launch' => ['kind' => 'uri', 'target' => 'steam://rungameid/13210']]);

    expect($steam->launchTarget())->toBe('steam://rungameid/13210//-novid');
    expect($uri->launchTarget())->toBe('steam://rungameid/13210');
});

it('prefers launch_targets over the flat launch field and keeps the declared order', function () {
    $recipe = recipeWithMetadata([
        'launch' => 'C:\\Games\\UT2004\\System\\UT2004.exe',
        'launch_targets' => [
            ['id' => 'flat', 'label' => 'Flat', 'os' => 'windows', 'command' => '{{target}}/System/UT2004.exe'],
            ['id' => 'vr', 'label' => 'VR', 'os' => 'windows', 'command' => '{{target}}/System/Play-VR.bat', 'shell' => 'powershell', 'notes' => 'SteamVR'],
        ],
    ]);

    $targets = $recipe->launchTargets();

    expect($targets)->toHaveCount(2);
    expect(array_column($targets, 'id'))->toBe(['flat', 'vr']);
    expect($targets[1]['shell'])->toBe('powershell');
    expect($targets[1]['notes'])->toBe('SteamVR');
    expect($recipe->launchTarget())->toBe('{{target}}/System/UT2004.exe');
    expect($recipe->launchTarget('vr'))->toBe('{{target}}/System/Play-VR.bat');
    expect($recipe->launchTarget('nope'))->toBeNull();
});

it('fills defaults for a sparse launch_targets entry and skips unusable ones', function () {
    $recipe = recipeWithMetadata([
        'launch_targets' => [
            ['id' => 'flat', 'label' => 'Flat', 'os' => 'linux'],
            ['label' => 'No id', 'command' => 'run --game'],
            'not-a-map',
        ],
    ]);

    $targets = $recipe->launchTargets();

    expect($targets)->toHaveCount(1);
    expect($targets[0]['id'])->toBe('target-2');
    expect($targets[0]['os'])->toBe('any');
    expect($targets[0]['command'])->toBe('run --game');
});

it('keeps the legacy launch_vr as a vr entry when launch_targets has none', function () {
    $recipe = recipeWithMetadata([
        'launch' => 'ut2004',
        'launch_vr' => 'ut2004-vr',
    ]);

    expect(array_column($recipe->launchTargets(), 'id'))->toBe(['default', 'vr']);
    expect($recipe->launchTarget('vr'))->toBe('ut2004-vr');
});

it('does not duplicate an explicit vr entry with the legacy field', function () {
    $recipe = recipeWithMetadata([
        'launch_targets' => [['id' => 'vr', 'label' => 'VR', 'os' => 'any', 'command' => 'a']],
        'launch_vr' => 'b',
    ]);

    expect($recipe->launchTargets())->toHaveCount(1);
    expect($recipe->launchTarget())->toBe('a');
});

it('returns nothing when the recipe has no launch information', function () {
    $recipe = recipeWithMetadata(['genre' => 'racing', 'players' => '1', 'lan_recommended' => true, 'tags' => []]);

    expect($recipe->launchTargets())->toBe([]);
    expect($recipe->launchTarget())->toBeNull();
});

it('substitutes the {{host}} template in play_url', function () {
    $recipe = recipeWithMetadata(['play_url' => 'http://{{host}}:8080/play?game=r4']);

    expect($recipe->playUrlFor('lan-party.local'))->toBe('http://lan-party.local:8080/play?game=r4');
    expect($recipe->playUrlFor('10.0.0.5'))->toBe('http://10.0.0.5:8080/play?game=r4');
    expect($recipe->playUrl())->toBe('http://{{host}}:8080/play?game=r4');
});

it('leaves a play_url without the template alone', function () {
    $recipe = recipeWithMetadata(['play_url' => 'https://ut.example.org/']);

    expect($recipe->playUrlFor('whatever'))->toBe('https://ut.example.org/');
});
