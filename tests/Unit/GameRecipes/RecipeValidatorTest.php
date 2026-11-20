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
