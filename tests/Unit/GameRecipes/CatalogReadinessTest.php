<?php

declare(strict_types=1);

use App\Modules\GameRecipes\Support\CatalogReadiness;
use App\Modules\GameRecipes\Support\RecipeValidator;
use Symfony\Component\Yaml\Yaml;

/**
 * The pull request must not open red: `.github/workflows/recipes.yml` runs
 * `resources/recipes/schema/validate.mjs` on every recipe pull request. These
 * tests pin the rules that can be decided from the exported text alone, and
 * the messages that tell the orga what to change.
 */

/**
 * @param  array<string, mixed>  $overrides
 */
function readinessYaml(array $overrides = []): string
{
    $data = [
        'schema' => RecipeValidator::SCHEMA,
        'slug' => 'tiny-racer',
        'title' => 'Tiny Racer',
        'aliases' => [],
        'version' => '1.0',
        'status' => 'draft',
        'platforms' => ['windows'],
        'summary' => 'A small racing game shared at the LAN.',
        'description' => 'A portable racing game. This draft needs verification on the LAN before the orga publishes it.',
        'license' => 'Game files are user-supplied.',
        'skill' => [
            'name' => 'tiny-racer',
            'description' => 'Install Tiny Racer from the LAN share when a player asks for it.',
        ],
        'requires' => [[
            'role' => 'base',
            'label' => 'Tiny Racer (portable archive)',
            'match' => ['tiny-racer.zip'],
            'required' => true,
        ]],
        'steps' => readinessSteps(3),
        'metadata' => [
            'genre' => 'other',
            'players' => '1+',
            'lan_recommended' => true,
            'tags' => ['shared'],
        ],
        ...$overrides,
    ];

    return Yaml::dump($data, 8, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return list<array<string, mixed>>
 */
function readinessSteps(int $count = 3, array $overrides = []): array
{
    $steps = [];

    for ($index = 0; $index < $count; $index++) {
        $steps[] = [
            'id' => 'step-'.($index + 1),
            'title' => 'Step '.($index + 1),
            'os' => 'any',
            'optional' => false,
            'detail' => 'Do the thing.',
            ...$overrides,
        ];
    }

    return $steps;
}

/** Exactly `$lines` lines, no trailing newline, so the count is predictable. */
function readinessSkill(int $lines = 70, string $name = 'tiny-racer', string $description = 'Install Tiny Racer from the LAN share when a player asks for it.'): string
{
    $skill = ['---', 'name: '.$name, 'description: "'.$description.'"', '---', '', '# Tiny Racer', ''];

    while (count($skill) < $lines) {
        $skill[] = 'A line of the guide.';
    }

    return implode("\n", array_slice($skill, 0, $lines));
}

it('accepts a complete catalogue document', function () {
    $problems = (new CatalogReadiness)->problems([
        'yaml' => readinessYaml(),
        'skill' => readinessSkill(),
    ]);

    expect($problems)->toBe([]);
});

it('blocks a bare MCP contribution and lists every reason', function () {
    $problems = (new CatalogReadiness)->problems([
        'yaml' => readinessYaml([
            'description' => 'A portable racing game.',
            'steps' => readinessSteps(1),
        ]),
        'skill' => readinessSkill(19),
    ]);

    $joined = implode("\n", $problems);

    expect($problems)->not->toBeEmpty()
        ->and($joined)->toContain('SKILL.md has 19 lines')
        ->and($joined)->toContain('60 to 150')
        ->and($joined)->toContain('has 1 step')
        ->and($joined)->toContain('3 to 6 steps for a draft')
        ->and($joined)->toContain('needs verification on the LAN');
});

it('does not hold a published recipe to the draft rules', function () {
    $problems = (new CatalogReadiness)->problems([
        'yaml' => readinessYaml([
            'status' => 'published',
            'description' => 'A portable racing game.',
            'steps' => readinessSteps(1),
        ]),
        'skill' => readinessSkill(),
    ]);

    expect($problems)->toBe([]);
});

it('refuses a SKILL.md outside the line range or without working front matter', function (string $skill, string $expected) {
    $problems = (new CatalogReadiness)->problems([
        'yaml' => readinessYaml(),
        'skill' => $skill,
    ]);

    expect($problems)->not->toBeEmpty()
        ->and(implode("\n", $problems))->toContain($expected);
})->with([
    'too short' => [readinessSkill(59), 'has 59 lines'],
    'too long' => [readinessSkill(151), 'has 151 lines'],
    'no front matter' => [implode("\n", array_slice(explode("\n", readinessSkill()), 4)), 'no YAML front matter'],
    'wrong name' => [readinessSkill(70, 'other-game'), 'does not match'],
]);

it('refuses a command chain, a shell that does not fit the step OS and a match glob with a separator', function () {
    $problems = (new CatalogReadiness)->problems([
        'yaml' => readinessYaml([
            'requires' => [[
                'role' => 'base',
                'label' => 'Tiny Racer (portable archive)',
                'match' => ['sub/dir/tiny-racer.zip'],
                'required' => true,
            ]],
            'steps' => [
                [
                    'id' => 'chain',
                    'title' => 'Chain',
                    'os' => 'any',
                    'optional' => false,
                    'detail' => 'Do the thing.',
                    'commands' => [['shell' => 'bash', 'run' => 'mkdir -p "{{target}}" && bsdtar -xf game.zip']],
                ],
                [
                    'id' => 'windows-bash',
                    'title' => 'Windows with bash',
                    'os' => 'windows',
                    'optional' => false,
                    'detail' => 'Do the thing.',
                    'commands' => [['shell' => 'bash', 'run' => 'unzip game.zip']],
                ],
            ],
        ]),
        'skill' => readinessSkill(),
    ]);

    $joined = implode("\n", $problems);

    expect($joined)->toContain('&&')
        ->and($joined)->toContain('os: windows')
        ->and($joined)->toContain('powershell or cmd')
        ->and($joined)->toContain('path separator');
});

it('refuses a command run on a linux step that is not bash', function () {
    $problems = (new CatalogReadiness)->problems([
        'yaml' => readinessYaml([
            'steps' => [[
                'id' => 'linux-powershell',
                'title' => 'Linux with PowerShell',
                'os' => 'linux',
                'optional' => false,
                'detail' => 'Do the thing.',
                'commands' => [['shell' => 'powershell', 'run' => 'Expand-Archive']],
            ]],
        ]),
        'skill' => readinessSkill(),
    ]);

    expect(implode("\n", $problems))->toContain('os: linux')->toContain('bash');
});

it('refuses files that would land in a directory named differently from the slug', function () {
    $problems = (new CatalogReadiness)->problems([
        'yaml' => readinessYaml(),
        'skill' => readinessSkill(),
    ], 'other-directory');

    expect(implode("\n", $problems))->toContain('does not equal the directory');
});

it('refuses a draft whose prose never mentions the LAN verification', function () {
    $problems = (new CatalogReadiness)->problems([
        'yaml' => readinessYaml([
            'description' => 'A nice game.',
            'steps' => readinessSteps(3, ['detail' => 'Do the thing.']),
        ]),
        'skill' => readinessSkill(),
    ]);

    expect(implode("\n", $problems))->toContain('needs verification on the LAN');
});
