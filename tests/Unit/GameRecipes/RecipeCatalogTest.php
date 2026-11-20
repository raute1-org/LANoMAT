<?php

declare(strict_types=1);

use App\Modules\GameRecipes\Support\RecipeParser;
use App\Modules\GameRecipes\Support\RecipeValidator;
use App\Modules\GameRecipes\Support\SkillRenderer;
use Symfony\Component\Yaml\Yaml;

/**
 * The bundled catalog is shipped data: every recipe in resources/recipes must
 * parse and validate, so a contributor cannot break `lanomat:recipes:sync`
 * with a typo that only shows up on the LAN.
 */
it('ships only valid bundled recipes', function () {
    $root = dirname(__DIR__, 3).'/resources/recipes';
    $files = glob($root.'/*/recipe.yaml') ?: [];

    expect($files)->not->toBe([]);

    $validator = new RecipeValidator;
    $parser = new RecipeParser($validator, new SkillRenderer);
    $slugs = [];
    $skillNames = [];

    foreach ($files as $path) {
        $data = Yaml::parseFile($path);

        expect($data)->toBeArray();

        $validator->validate($data, $path);

        $parsed = $parser->parseFile($path, 'bundled');

        expect($parsed['recipe']['slug'])->toBe(basename(dirname($path)))
            ->and($parsed['recipe']['skill_markdown'])->toContain('name:')
            ->and($parsed['recipe']['steps'])->not->toBe([]);

        $slugs[] = $parsed['recipe']['slug'];

        preg_match('/^name:\s*"?([a-z0-9-]+)"?$/m', (string) $parsed['recipe']['skill_markdown'], $m);
        $skillNames[] = $m[1] ?? '';
    }

    expect($slugs)->toBe(array_values(array_unique($slugs)), 'duplicate recipe slugs')
        ->and($skillNames)->toBe(array_values(array_unique($skillNames)), 'duplicate skill names');

    // The flagship MVP must always be present and installable.
    expect($slugs)->toContain('unreal-tournament-2004', 'ridge-racer-type-4');
});
