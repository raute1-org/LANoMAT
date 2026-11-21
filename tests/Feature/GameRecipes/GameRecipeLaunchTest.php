<?php

declare(strict_types=1);

use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function launchRecipe(array $metadata): GameRecipe
{
    return GameRecipe::query()->create([
        'slug' => 'launch-'.substr(md5((string) json_encode($metadata)), 0, 8),
        'title' => 'Launch probe',
        'status' => 'draft',
        'source' => 'bundled',
        'platforms' => ['windows', 'linux'],
        'summary' => 'Probe.',
        'description' => 'Probe.',
        'skill_markdown' => "---\nname: probe\ndescription: Probe.\n---\n",
        'requirements' => [],
        'steps' => [],
        'metadata' => $metadata,
    ]);
}

it('reads a legacy string launch target', function () {
    expect(launchRecipe(['launch' => 'C:\\Games\\UT2004.exe'])->launchTarget())
        ->toBe('C:\\Games\\UT2004.exe');
});

it('reads the canonical launch object', function () {
    $recipe = launchRecipe(['launch' => [
        'kind' => 'steam',
        'target' => 'steam://rungameid/10',
        'notes' => 'Join with steam://connect.',
    ]]);

    expect($recipe->launchTarget())->toBe('steam://rungameid/10');
});

it('picks a launch target by id or operating system', function () {
    $recipe = launchRecipe(['launch' => ['kind' => 'exe', 'target' => '{{target}}/game.exe'], 'launch_targets' => [
        ['id' => 'flat', 'label' => 'Flat', 'os' => 'any', 'kind' => 'exe', 'target' => '{{target}}/game.exe'],
        ['id' => 'vr', 'label' => 'VR', 'os' => 'windows', 'kind' => 'exe', 'target' => '{{target}}/game-vr.exe'],
        ['id' => 'linux', 'label' => 'Linux', 'os' => 'linux', 'kind' => 'exe', 'target' => '{{target}}/game'],
    ]]);

    expect($recipe->launchTarget('vr'))->toBe('{{target}}/game-vr.exe')
        ->and($recipe->launchTarget(null, 'linux'))->toBe('{{target}}/game')
        // The first target declared for the requested OS wins.
        ->and($recipe->launchTarget(null, 'windows'))->toBe('{{target}}/game-vr.exe')
        ->and($recipe->launchTarget('missing'))->toBe('{{target}}/game.exe');
});

it('substitutes the request host in a play url', function () {
    $recipe = launchRecipe(['play_url' => 'http://{{host}}:8080/play?game=r4']);

    expect($recipe->playUrl('lan.raute1.org'))->toBe('http://lan.raute1.org:8080/play?game=r4')
        ->and($recipe->playUrl())->toBe('http://{{host}}:8080/play?game=r4')
        ->and(launchRecipe([])->playUrl('lan.raute1.org'))->toBeNull();
});
