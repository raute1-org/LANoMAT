<?php

declare(strict_types=1);

use App\Modules\GameRecipes\Exceptions\RecipeException;
use App\Modules\GameRecipes\Support\RecipeValidator;

function validRecipe(): array
{
    return [
        'schema' => RecipeValidator::SCHEMA,
        'slug' => 'unreal-tournament-2004',
        'title' => 'Unreal Tournament 2004',
        'aliases' => ['ut2004'],
        'status' => 'published',
        'platforms' => ['windows'],
        'skill' => [
            'name' => 'ut2004',
            'description' => 'Install Unreal Tournament 2004 from the LAN. Use when the user asks to install ut2004.',
        ],
        'requirements' => [
            ['role' => 'iso', 'label' => 'UT2004 DVD', 'match' => ['ut2004*.iso'], 'required' => true],
            ['role' => 'avatar', 'label' => 'Alyx', 'match' => ['alyx*.vrm'], 'required' => false],
        ],
        'steps' => [
            ['id' => 'install', 'title' => 'Install', 'os' => 'windows', 'commands' => [['shell' => 'powershell', 'run' => 'setup.exe']]],
            ['id' => 'vr', 'title' => 'VR', 'os' => 'windows', 'optional' => true, 'commands' => [['shell' => 'powershell', 'run' => 'install.bat']]],
        ],
    ];
}

it('accepts a valid recipe', function () {
    app(RecipeValidator::class)->validate(validRecipe());

    expect(true)->toBeTrue();
});

it('collects every problem in one pass', function () {
    $recipe = validRecipe();
    $recipe['schema'] = 'wrong';
    $recipe['slug'] = 'Not A Slug';
    $recipe['status'] = 'nope';
    $recipe['platforms'] = [];
    $recipe['skill']['name'] = 'Bad Name';
    $recipe['steps'][0]['id'] = '';

    try {
        app(RecipeValidator::class)->validate($recipe);
    } catch (RecipeException $exception) {
        expect(count($exception->problems))->toBeGreaterThanOrEqual(6);

        return;
    }

    $this->fail('Expected a RecipeException.');
});

it('requires match patterns or an external url', function () {
    $recipe = validRecipe();
    $recipe['requirements'][0] = ['role' => 'iso', 'label' => 'UT2004 DVD'];

    expect(fn () => app(RecipeValidator::class)->validate($recipe))
        ->toThrow(RecipeException::class);
});

it('accepts macos steps, verifications and launch targets', function () {
    $recipe = validRecipe();
    $recipe['platforms'] = ['windows', 'linux', 'macos'];
    $recipe['steps'][0]['os'] = 'macos';
    $recipe['steps'][0]['verify'] = [
        ['type' => 'dir_exists', 'path' => '~/Applications/UT2004.app', 'os' => 'macos'],
        ['type' => 'command', 'command' => 'test -d "$HOME/Applications/UT2004.app"', 'os' => 'macos'],
    ];
    $recipe['launch_targets'] = [
        [
            'id' => 'flat-macos',
            'label' => 'Flat on a monitor (macOS)',
            'os' => 'macos',
            'shell' => 'bash',
            'command' => 'open "$HOME/Applications/UT2004.app"',
        ],
    ];

    app(RecipeValidator::class)->validate($recipe);

    expect(true)->toBeTrue();
});

it('rejects an unknown os on a step, verification or launch target', function () {
    $step = validRecipe();
    $step['steps'][0]['os'] = 'amiga';

    expect(fn () => app(RecipeValidator::class)->validate($step))
        ->toThrow(RecipeException::class);

    $verification = validRecipe();
    $verification['steps'][0]['verify'] = [
        ['type' => 'command', 'command' => 'true', 'os' => 'amiga'],
    ];

    expect(fn () => app(RecipeValidator::class)->validate($verification))
        ->toThrow(RecipeException::class);

    $launchTarget = validRecipe();
    $launchTarget['launch_targets'] = [
        ['id' => 'flat', 'label' => 'Flat', 'os' => 'amiga', 'command' => 'true'],
    ];

    expect(fn () => app(RecipeValidator::class)->validate($launchTarget))
        ->toThrow(RecipeException::class);
});

it('rejects duplicate step ids and unknown shells', function () {
    $recipe = validRecipe();
    $recipe['steps'][1]['id'] = 'install';
    $recipe['steps'][0]['commands'][0]['shell'] = 'fish';

    try {
        app(RecipeValidator::class)->validate($recipe);
    } catch (RecipeException $exception) {
        $text = $exception->summary();

        expect($text)->toContain('duplicated')
            ->and($text)->toContain('shell must be');

        return;
    }

    $this->fail('Expected a RecipeException.');
});
