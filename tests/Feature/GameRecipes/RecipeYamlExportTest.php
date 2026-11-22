<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Actions\ContributeRecipe;
use App\Modules\GameRecipes\Actions\ExportRecipes;
use App\Modules\GameRecipes\Actions\SyncRecipes;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Exceptions\RecipeException;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\RecipeParser;
use App\Modules\GameRecipes\Support\RecipeValidator;
use App\Modules\GameRecipes\Support\RecipeYamlExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

/**
 * The exporter is the return path of the catalogue: a row from the database
 * becomes `recipe.yaml` + `SKILL.md` again, so a shared recipe can travel into
 * git as a pull request and the parser must read it back unchanged.
 */
function recipeYamlTempDir(string $suffix): string
{
    $dir = sys_get_temp_dir().'/lanomat-recipe-yaml-'.$suffix.'-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($dir, 0755);

    return $dir;
}

/** @return array<string, mixed> */
function recipeYamlSchema(): array
{
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3).'/resources/recipes/schema/recipe.schema.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    /** @var array<string, mixed> $schema */
    return $schema;
}

/**
 * Sort associative keys recursively, so comparing two arrays does not depend
 * on YAML author order or on PostgreSQL jsonb's key order.
 */
function recipeYamlCanonical(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $sorted = [];

    foreach ($value as $key => $item) {
        $sorted[$key] = recipeYamlCanonical($item);
    }

    if (! array_is_list($sorted)) {
        ksort($sorted);
    }

    return $sorted;
}

/**
 * The schema view of one requirement: defaults applied, empty optional values
 * dropped, keys the schema does not allow left out.
 *
 * @param  array<string, mixed>  $requirement
 * @return array<string, mixed>
 */
function recipeYamlRequirementView(array $requirement): array
{
    $role = (string) ($requirement['role'] ?? '');

    $view = [
        'role' => $role,
        'label' => (string) ($requirement['label'] ?? ''),
        'match' => array_values(array_map('strval', (array) ($requirement['match'] ?? []))),
        'required' => (bool) ($requirement['required'] ?? in_array($role, ['base', 'iso'], true)),
    ];

    $os = $requirement['os'] ?? null;

    if (in_array($os, ['windows', 'linux', 'macos'], true)) {
        $view['os'] = $os;
    }

    if (isset($requirement['size_bytes'])) {
        $view['size_bytes'] = (int) $requirement['size_bytes'];
    }

    if (($requirement['sha256'] ?? '') !== '') {
        $view['sha256'] = mb_strtolower((string) $requirement['sha256']);
    }

    if (($requirement['url'] ?? '') !== '') {
        $view['url'] = (string) $requirement['url'];
    }

    if (($requirement['notes'] ?? '') !== '') {
        $view['notes'] = (string) $requirement['notes'];
    }

    if (($requirement['tags'] ?? []) !== []) {
        $view['tags'] = array_values(array_map('strval', (array) $requirement['tags']));
    }

    return $view;
}

/**
 * The schema view of one step.
 *
 * @param  array<string, mixed>  $step
 * @return array<string, mixed>
 */
function recipeYamlStepView(array $step, int $index): array
{
    $title = (string) ($step['title'] ?? 'Step '.($index + 1));
    $view = [
        'id' => (string) ($step['id'] ?? 'step-'.($index + 1)),
        'title' => $title,
        'os' => (string) ($step['os'] ?? 'any'),
        'optional' => (bool) ($step['optional'] ?? false),
        'detail' => (string) ($step['detail'] ?? $title),
    ];

    $commands = [];

    foreach ((array) ($step['commands'] ?? []) as $command) {
        if (is_array($command) && ($command['run'] ?? '') !== '') {
            $commands[] = [
                'shell' => (string) ($command['shell'] ?? 'bash'),
                'run' => (string) $command['run'],
            ];
        }
    }

    if ($commands !== []) {
        $view['commands'] = $commands;
    }

    if (($step['verify'] ?? []) !== []) {
        $view['verify'] = array_values((array) $step['verify']);
    }

    return $view;
}

/**
 * A contributed draft with a realistic SKILL.md: long enough for the catalogue
 * validator (60-150 lines) and honest about needing a LAN check.
 *
 * @return array<string, mixed>
 */
function recipeYamlDraftPayload(): array
{
    $skill = <<<'MD'
---
name: lan-test-racer
description: 'Install LAN Test Racer from the shared portable archive at the LAN, unpack it into the target folder and start it on Windows or Linux. Use when the user asks to install LAN Test Racer or join a race on the LAN.'
---

# LAN Test Racer

LAN Test Racer is a small arcade racer used to prove the sharing flow.

## When to use

Use this skill when the user asks to install LAN Test Racer, or when a LAN
server card offers it and the game is not installed yet.

## What the installer needs

- The shared archive `lan-test-racer-1.0.zip` from the LAN file host.
- A target folder the user owns, for example `C:\Games\LanTestRacer`.

## Steps

1. Ask for the target folder and unpack the archive there.
2. Confirm that `lan-test-racer.exe` (Windows) or `lan-test-racer` (Linux)
   sits directly in the target folder.
3. Start the game once and check that the main menu appears.
4. Point the player at the LAN server from the server browser.

## Windows

Unpack the archive with Explorer or Expand-Archive, then start the executable.
Windows may show a SmartScreen prompt for the unsigned build; use "More info"
and "Run anyway".

## Linux

Unpack the archive with `bsdtar` or `unzip`, make the binary executable and
start it from the target folder. No Wine is needed for this test build.

## Troubleshooting

- The game starts to a black window: check that the `assets/` folder is next to
  the executable.
- The server list is empty: the player is on the wrong LAN or the server host
  is not reachable.
- Antivirus quarantines the executable: allow the target folder.

## Verification

This draft needs verification on the LAN hardware: framerate, gamepad support
and the join flow were not measured yet.

## Notes

The archive is user-supplied and never redistributed by LANoMAT.
MD;

    return [
        'via' => 'web',
        'slug' => 'lan-test-racer',
        'title' => 'LAN Test Racer',
        'aliases' => ['ltr', 'test racer'],
        'version' => '1.0',
        'platforms' => ['windows', 'linux'],
        'summary' => 'A ready-to-run test racer shared at the LAN, unpacked by the launcher and started from its own executable.',
        'description' => "LAN Test Racer is a small arcade racer shared by a participant.\n\nThe draft needs verification on the LAN hardware.",
        'homepage' => 'https://lan.example/games/lan-test-racer',
        'license' => 'Game files are user-supplied.',
        'skill_description' => 'Install LAN Test Racer from the shared portable archive at the LAN, unpack it into the target folder and start it on Windows or Linux. Use when the user asks to install LAN Test Racer.',
        'skill_markdown' => $skill,
        'requirements' => [
            [
                'role' => 'base',
                'label' => 'LAN Test Racer (portable archive)',
                'match' => ['lan-test-racer*.zip'],
                'required' => true,
            ],
            [
                'role' => 'tool',
                'label' => 'Unzip helper (optional)',
                'match' => [],
                'required' => false,
                'url' => 'https://www.7-zip.org/download.html',
            ],
        ],
        'steps' => [
            [
                'id' => 'ask-target',
                'title' => 'Ask for the target folder',
                'os' => 'any',
                'optional' => false,
                'detail' => 'Ask where the game should live and create the folder if it does not exist.',
            ],
            [
                'id' => 'unpack',
                'title' => 'Unpack the shared archive',
                'os' => 'windows',
                'optional' => false,
                'detail' => 'Unpack `lan-test-racer*.zip` into `{{target}}` and strip the wrapper folder.',
                'commands' => [
                    ['shell' => 'powershell', 'run' => 'Expand-Archive -Path "{{downloads}}/lan-test-racer*.zip" -DestinationPath "{{target}}" -Force'],
                ],
                'verify' => [
                    ['type' => 'file_exists', 'path' => '{{target}}/lan-test-racer.exe'],
                ],
            ],
            [
                'id' => 'unpack-linux',
                'title' => 'Unpack the shared archive (Linux)',
                'os' => 'linux',
                'optional' => false,
                'detail' => 'Unpack the archive with `bsdtar` and make the binary executable.',
                'commands' => [
                    ['shell' => 'bash', 'run' => 'bsdtar -xf "{{downloads}}/lan-test-racer-1.0.zip" -C "{{target}}"'],
                    ['shell' => 'bash', 'run' => 'chmod +x "{{target}}/lan-test-racer"'],
                ],
                'verify' => [
                    ['type' => 'file_exists', 'path' => '{{target}}/lan-test-racer'],
                ],
            ],
            [
                'id' => 'check-start',
                'title' => 'Start the game once',
                'os' => 'any',
                'optional' => false,
                'detail' => 'Start the game and confirm the main menu appears. This draft needs verification on the LAN hardware.',
                'verify' => [
                    ['type' => 'file_exists', 'path' => '{{target}}/assets'],
                ],
            ],
        ],
        'launch_targets' => [
            [
                'id' => 'windows',
                'label' => 'Windows',
                'os' => 'windows',
                'shell' => 'powershell',
                'command' => 'Start-Process -FilePath "{{target}}/lan-test-racer.exe" -WorkingDirectory "{{target}}"',
            ],
            [
                'id' => 'linux',
                'label' => 'Linux',
                'os' => 'linux',
                'shell' => 'bash',
                'command' => '"{{target}}/lan-test-racer"',
            ],
        ],
        'play_url' => 'https://lan.example/play/lan-test-racer',
        'metadata' => [
            'genre' => 'racing',
            'players' => '2-8',
            'players_max' => 8,
            'lan_recommended' => true,
            'tags' => ['racing', 'test'],
            'portable' => true,
            'multiplayer' => true,
        ],
    ];
}

it('mirrors the schema metadata allowlist', function () {
    $schema = recipeYamlSchema();
    $properties = array_keys((array) $schema['properties']['metadata']['properties']);
    sort($properties);

    $mirrored = RecipeYamlExporter::METADATA_KEYS;
    sort($mirrored);

    expect($mirrored)->toBe($properties);
});

it('round-trips every bundled recipe through the repository layout', function () {
    $source = dirname(__DIR__, 3).'/resources/recipes';
    $sync = app(SyncRecipes::class)->handle($source);

    expect($sync['skipped'])->toBe([]);

    $files = glob($source.'/*/recipe.yaml') ?: [];
    expect($files)->not->toBe([]);

    $out = recipeYamlTempDir('roundtrip');
    $exported = app(ExportRecipes::class)->handle($out, false, ExportRecipes::FORMAT_REPOSITORY);

    expect($exported['written'])->toBe(count($files));

    $schema = recipeYamlSchema();
    /** @var array<string, array<string, mixed>> $defs */
    $defs = (array) $schema['$defs'];
    $topLevelKeys = array_keys((array) $schema['properties']);
    $metadataKeys = RecipeYamlExporter::METADATA_KEYS;
    $requirementKeys = array_keys((array) $defs['requirement']['properties']);
    $stepKeys = array_keys((array) $defs['step']['properties']);
    $commandKeys = array_keys((array) $defs['command']['properties']);
    $verificationKeys = array_keys((array) $defs['verification']['properties']);
    $launchTargetKeys = array_keys((array) $defs['launchTarget']['properties']);
    $parser = app(RecipeParser::class);

    foreach ($files as $path) {
        $slug = basename(dirname($path));

        // Every authored key must be one the v1 schema allows: a recipe
        // that adds another key fails here instead of silently losing it on
        // export.
        $authored = Yaml::parseFile($path);
        expect($authored)->toBeArray();

        /** @var array<string, mixed> $authored */
        $unknown = [];

        foreach (array_diff(array_keys($authored), $topLevelKeys) as $key) {
            $unknown[] = $key;
        }

        foreach (array_diff(array_keys(is_array($authored['metadata'] ?? null) ? $authored['metadata'] : []), $metadataKeys) as $key) {
            $unknown[] = 'metadata.'.$key;
        }

        foreach ((array) ($authored['requires'] ?? []) as $index => $requirement) {
            foreach (array_diff(array_keys((array) $requirement), $requirementKeys) as $key) {
                $unknown[] = 'requires['.$index.'].'.$key;
            }
        }

        foreach ((array) ($authored['steps'] ?? []) as $index => $step) {
            foreach (array_diff(array_keys((array) $step), $stepKeys) as $key) {
                $unknown[] = 'steps['.$index.'].'.$key;
            }

            foreach ((array) (((array) $step)['commands'] ?? []) as $commandIndex => $command) {
                foreach (array_diff(array_keys((array) $command), $commandKeys) as $key) {
                    $unknown[] = 'steps['.$index.'].commands['.$commandIndex.'].'.$key;
                }
            }

            foreach ((array) (((array) $step)['verify'] ?? []) as $verifyIndex => $check) {
                foreach (array_diff(array_keys((array) $check), $verificationKeys) as $key) {
                    $unknown[] = 'steps['.$index.'].verify['.$verifyIndex.'].'.$key;
                }
            }
        }

        foreach ((array) ($authored['launch_targets'] ?? []) as $index => $target) {
            foreach (array_diff(array_keys((array) $target), $launchTargetKeys) as $key) {
                $unknown[] = 'launch_targets['.$index.'].'.$key;
            }
        }

        expect($unknown)->toBe([], $slug.' uses keys the schema does not allow');

        $original = $parser->parseFile($path, 'bundled');
        $roundPath = $out.'/'.$slug.'/recipe.yaml';

        expect(is_file($roundPath))->toBeTrue($slug.' was not exported')
            ->and(is_file($out.'/'.$slug.'/SKILL.md'))->toBeTrue($slug.' has no SKILL.md');

        try {
            $round = $parser->parseFile($roundPath, 'exported');
        } catch (RecipeException $exception) {
            $this->fail($slug.': the exported recipe.yaml does not parse: '.$exception->summary());
        }

        foreach (['slug', 'title', 'aliases', 'version', 'status', 'platforms', 'summary', 'description', 'homepage', 'license', 'cover_image_path'] as $field) {
            expect($round['recipe'][$field])->toBe($original['recipe'][$field], $slug.': '.$field.' changed in the round trip');
        }

        $originalRequirements = array_map(recipeYamlRequirementView(...), (array) $original['recipe']['requirements']);
        $roundRequirements = array_map(recipeYamlRequirementView(...), (array) $round['recipe']['requirements']);

        expect(recipeYamlCanonical($roundRequirements))->toBe(recipeYamlCanonical($originalRequirements), $slug.': requirements changed in the round trip');

        $originalSteps = [];
        $roundSteps = [];

        foreach (array_values((array) $original['recipe']['steps']) as $index => $step) {
            $originalSteps[] = recipeYamlStepView((array) $step, $index);
        }

        foreach (array_values((array) $round['recipe']['steps']) as $index => $step) {
            $roundSteps[] = recipeYamlStepView((array) $step, $index);
        }

        expect(recipeYamlCanonical($roundSteps))->toBe(recipeYamlCanonical($originalSteps), $slug.': steps changed in the round trip')
            ->and(recipeYamlCanonical($round['recipe']['metadata']))->toBe(recipeYamlCanonical($original['recipe']['metadata']), $slug.': metadata changed in the round trip')
            ->and(recipeYamlCanonical($round['files']))->toBe(recipeYamlCanonical($original['files']), $slug.': file rows changed in the round trip');
    }

    File::deleteDirectory($out);
});

it('exports a contributed draft that re-parses and carries no instance data', function () {
    $user = User::factory()->create();
    $file = SharedFile::factory()->create(['user_id' => $user->id, 'original_name' => 'lan-test-racer-1.0.zip']);

    $payload = recipeYamlDraftPayload();
    $payload['requirements'][0]['shared_file_id'] = $file->id;

    $recipe = app(ContributeRecipe::class)->handle($user, $payload);

    // Instance data that must never reach the YAML.
    $recipe->forceFill([
        'in_lineup' => true,
        'pr_number' => 42,
        'pr_url' => 'https://github.com/lanomat/recipes/pull/42',
        'pr_branch' => 'recipes/lan-test-racer',
        'pr_state' => PullRequestState::Open,
        'pr_synced_at' => now(),
    ])->save();

    $exported = app(RecipeYamlExporter::class)->export($recipe->fresh());

    expect($exported['skill'])->toEndWith("\n")
        ->and(str_ends_with($exported['skill'], "\n\n"))->toBeFalse()
        ->and($exported['yaml'])->toEndWith("\n")
        ->and(str_ends_with($exported['yaml'], "\n\n"))->toBeFalse();

    foreach (['shared_file_id', 'contributed_via', 'in_lineup', 'pr_number', 'pr_url', 'pr_branch', 'pr_state', 'contributor_user_id', 'reviewed_by', 'cover:'] as $forbidden) {
        expect($exported['yaml'])->not->toContain($forbidden, 'instance data "'.$forbidden.'" leaked into recipe.yaml');
    }

    $data = Yaml::parse($exported['yaml']);
    expect($data)->toBeArray();

    /** @var array<string, mixed> $data */
    $schema = recipeYamlSchema();
    $missing = array_values(array_diff((array) $schema['required'], array_keys($data)));
    $unknownMetadata = array_values(array_diff(
        array_keys((array) $data['metadata']),
        RecipeYamlExporter::METADATA_KEYS,
    ));

    expect($missing)->toBe([])
        ->and($unknownMetadata)->toBe([])
        ->and($data['schema'])->toBe('lanomat.recipe/v1')
        ->and($data['status'])->toBe('draft')
        ->and($data)->toHaveKey('launch_targets')
        ->and($data)->toHaveKey('play_url')
        ->and($data['metadata']['portable'])->toBeTrue();

    app(RecipeValidator::class)->validate($data, 'exported');

    $dir = recipeYamlTempDir('draft');
    File::put($dir.'/recipe.yaml', $exported['yaml']);
    File::put($dir.'/SKILL.md', $exported['skill']);

    $parsed = app(RecipeParser::class)->parseFile($dir.'/recipe.yaml', 'exported');

    expect($parsed['recipe']['slug'])->toBe('lan-test-racer')
        ->and($parsed['recipe']['summary'])->toBe($recipe->summary)
        ->and($parsed['recipe']['metadata']['contributed_via'] ?? null)->toBeNull()
        ->and($parsed['recipe']['metadata']['launch_targets'])->toEqual($payload['launch_targets'])
        ->and($parsed['recipe']['metadata']['play_url'])->toBe($payload['play_url'])
        ->and($parsed['recipe']['steps'])->toHaveCount(4)
        ->and($parsed['files'])->toHaveCount(2)
        // The pin lives on the file row and must not be in the text.
        ->and($parsed['files'][0]['sha256'])->toBeNull();

    File::deleteDirectory($dir);
});

it('exports valid store ids and drops invalid or unknown ones', function () {
    $user = User::factory()->create();
    $recipe = GameRecipe::factory()->contributedBy($user)->portable('lan-test-racer.zip', 'LanTestRacer.exe')->create([
        'slug' => 'store-id-export',
        'title' => 'Store Id Export',
    ]);
    $recipe->update(['metadata' => [
        ...(array) $recipe->metadata,
        'store_ids' => ['steam' => '10', 'epic' => 'Kinglet', 'gog' => '12x', 'origin' => '1', 'ubisoft' => 4],
    ]]);

    $data = Yaml::parse(app(RecipeYamlExporter::class)->export($recipe->fresh())['yaml']);

    expect($data['metadata']['store_ids'])->toBe(['steam' => '10', 'epic' => 'Kinglet']);
    app(RecipeValidator::class)->validate($data, 'exported');

    // Nothing valid left: the key is omitted, not written empty.
    $recipe->update(['metadata' => [...(array) $recipe->metadata, 'store_ids' => ['gog' => 'x']]]);
    $data = Yaml::parse(app(RecipeYamlExporter::class)->export($recipe->fresh())['yaml']);

    expect($data['metadata'])->not->toHaveKey('store_ids');
});

it('exports a factory-contributed portable draft and honours honest defaults', function () {
    $user = User::factory()->create();
    $recipe = GameRecipe::factory()->contributedBy($user)->portable('lan-test-racer.zip', 'LanTestRacer.exe')->create([
        'slug' => 'factory-portable-draft',
        'title' => 'Factory Portable Draft',
        'summary' => null,
        'description' => null,
        'license' => null,
        'aliases' => null,
        'version' => null,
        'homepage' => null,
        'skill_markdown' => "---\nname: factory-portable-draft\ndescription: \"Install the portable draft from the LAN.\"\n---\n# Factory Portable Draft\n",
    ]);

    $shared = SharedFile::factory()->approved()->create();
    $recipe->files()->create([
        'role' => 'base',
        'label' => 'Portable archive',
        'match_patterns' => ['lan-test-racer.zip'],
        'required' => true,
        'sort' => 0,
        'shared_file_id' => $shared->id,
    ]);
    $recipe->forceFill(['in_lineup' => true])->save();

    $exported = app(RecipeYamlExporter::class)->export($recipe->fresh());
    $data = Yaml::parse($exported['yaml']);

    expect($data)->toBeArray();

    /** @var array<string, mixed> $data */
    $schema = recipeYamlSchema();

    expect(array_values(array_diff((array) $schema['required'], array_keys($data))))->toBe([])
        ->and($data['aliases'])->toBe([])
        ->and($data['version'])->toBe('1.0')
        ->and($data['summary'])->toBe('Factory Portable Draft')
        ->and($data['description'])->toBe('Factory Portable Draft')
        ->and($data['license'])->toBe('Game files are user-supplied.')
        ->and($data['metadata']['genre'])->toBe('other')
        ->and($data['metadata']['players'])->toBe('1+')
        ->and($data['metadata']['lan_recommended'])->toBeTrue()
        ->and($data['metadata']['tags'])->toBe([])
        ->and($data['requires'][0]['match'])->toBe(['lan-test-racer.zip'])
        ->and($data['requires'][0]['required'])->toBeTrue();

    app(RecipeValidator::class)->validate($data, 'factory');

    foreach (['shared_file_id', 'contributed_via', 'in_lineup', 'pr_', 'contributor', 'reviewed'] as $forbidden) {
        expect($exported['yaml'])->not->toContain($forbidden);
    }

    $dir = recipeYamlTempDir('factory');
    File::put($dir.'/recipe.yaml', $exported['yaml']);
    File::put($dir.'/SKILL.md', $exported['skill']);

    $parsed = app(RecipeParser::class)->parseFile($dir.'/recipe.yaml', 'exported');

    expect($parsed['recipe']['slug'])->toBe('factory-portable-draft')
        ->and($parsed['recipe']['metadata']['portable'])->toBeTrue()
        ->and($parsed['files'])->toHaveCount(1)
        ->and($parsed['files'][0]['required'])->toBeTrue();

    File::deleteDirectory($dir);
});

it('writes both export formats and rejects an unknown one', function () {
    GameRecipe::factory()->published()->create([
        'slug' => 'export-format-test',
        'title' => 'Export Format Test',
        'requirements' => [[
            'role' => 'base',
            'label' => 'Archive',
            'match' => ['export-format-test.zip'],
            'required' => true,
        ]],
        'steps' => [[
            'id' => 'install',
            'title' => 'Install',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Unpack the archive.',
        ]],
        'skill_markdown' => "---\nname: export-format-test\ndescription: \"Install the export format test game from the LAN.\"\n---\n# Export Format Test\n",
    ]);

    $skills = recipeYamlTempDir('skills');
    $exit = Artisan::call('lanomat:recipes:export', ['path' => $skills]);

    expect($exit)->toBe(0)
        ->and(File::exists($skills.'/export-format-test/SKILL.md'))->toBeTrue()
        ->and(File::exists($skills.'/export-format-test/recipe.json'))->toBeTrue()
        ->and(File::exists($skills.'/export-format-test/recipe.yaml'))->toBeFalse();

    $repository = recipeYamlTempDir('repository');
    $exit = Artisan::call('lanomat:recipes:export', [
        'path' => $repository,
        '--format' => ExportRecipes::FORMAT_REPOSITORY,
    ]);

    expect($exit)->toBe(0)
        ->and(File::exists($repository.'/export-format-test/recipe.yaml'))->toBeTrue()
        ->and(File::exists($repository.'/export-format-test/SKILL.md'))->toBeTrue()
        ->and(File::exists($repository.'/export-format-test/recipe.json'))->toBeFalse();

    $unparsed = Yaml::parseFile($repository.'/export-format-test/recipe.yaml');
    expect($unparsed)->toBeArray()
        ->and($unparsed['slug'])->toBe('export-format-test');

    $exit = Artisan::call('lanomat:recipes:export', [
        'path' => recipeYamlTempDir('unknown'),
        '--format' => 'json',
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Unknown format "json"');

    File::deleteDirectory($skills);
    File::deleteDirectory($repository);
});

it('builds a catalog row with escaped pipes', function () {
    $recipe = new GameRecipe([
        'slug' => 'catalog-test',
        'title' => 'Catalog | Test',
        'platforms' => ['windows', 'linux'],
        'status' => RecipeStatus::Draft,
        'summary' => 'One line about the game.',
    ]);

    expect(app(RecipeYamlExporter::class)->catalogRow($recipe))
        ->toBe('| `catalog-test` | Catalog \\| Test | windows, linux | draft | One line about the game. |');
});

it('keeps a hand-supplied requirement required and a single-player flag', function () {
    $recipe = GameRecipe::factory()->published()->create([
        'slug' => 'solo-quest',
        'title' => 'Solo Quest',
        'summary' => 'A single-player game the player brings along.',
        'requirements' => [[
            'role' => 'content',
            'label' => 'Your own copy of the game',
            'match' => [],
            'required' => true,
            'manual' => true,
        ]],
        'steps' => [[
            'id' => 'check-install',
            'title' => 'Check the install',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Point the installer at your copy.',
        ]],
        'metadata' => ['multiplayer' => false],
    ]);

    $exported = Yaml::parse(app(RecipeYamlExporter::class)->export($recipe)['yaml']);

    expect($exported['requires'][0]['required'])->toBeTrue()
        ->and($exported['requires'][0]['manual'])->toBeTrue()
        ->and($exported['metadata']['multiplayer'])->toBeFalse();
});
