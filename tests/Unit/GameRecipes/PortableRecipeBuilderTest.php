<?php

declare(strict_types=1);

use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Support\PortableRecipeBuilder;
use App\Modules\GameRecipes\Support\RecipeValidator;

/**
 * The builder is pure enough to test without the database: it only reads the
 * file row it is handed. The assembled recipe is put through the same
 * RecipeValidator ContributeRecipe runs.
 */

/** A pinned archive row with a known id, without hitting the database. */
function gameShareArchive(string $name, ?string $sha256 = null, int $size = 4096): SharedFile
{
    return (new SharedFile)->forceFill([
        'id' => 17,
        'event_id' => 3,
        'user_id' => 5,
        'disk' => 'local',
        'path' => 'event-3/'.$name,
        'original_name' => $name,
        'size_bytes' => $size,
        'sha256' => $sha256,
        'mime' => 'application/zip',
        'visibility' => FileVisibility::Approved,
    ]);
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function gameShareAssembledRecipe(array $payload): array
{
    $requirements = array_map(static function (array $requirement): array {
        unset($requirement['shared_file_id']);

        return $requirement;
    }, $payload['requires']);

    return [
        'schema' => RecipeValidator::SCHEMA,
        'slug' => $payload['slug'],
        'title' => $payload['title'],
        'aliases' => [],
        'version' => $payload['version'],
        'status' => 'draft',
        'platforms' => $payload['platforms'],
        'summary' => $payload['summary'],
        'description' => $payload['description'],
        'homepage' => $payload['homepage'] ?? null,
        'license' => $payload['license'],
        'skill' => ['name' => $payload['slug'], 'description' => $payload['skill_description']],
        'requirements' => $requirements,
        'steps' => $payload['steps'],
        'metadata' => $payload['metadata'] + [
            'launch_targets' => $payload['launch_targets'],
            'contributed_via' => 'web',
        ],
    ];
}

/** @return array<string, mixed> */
function gameShareInput(array $overrides = []): array
{
    return [
        'title' => 'Tiny Racer',
        'shared_file_id' => 17,
        'platforms' => ['windows'],
        'executable' => 'bin/TinyRacer.exe',
        'multiplayer' => true,
        ...$overrides,
    ];
}

it('builds a draft portable recipe that passes the recipe validator for both archive types', function (string $archive) {
    $payload = app(PortableRecipeBuilder::class)->build(
        gameShareInput(['summary' => 'A small racer for the LAN.', 'version' => '1.4']),
        gameShareArchive($archive, str_repeat('a', 64), 987_654),
    );

    app(RecipeValidator::class)->validate(gameShareAssembledRecipe($payload));

    expect($payload['slug'])->toBe('tiny-racer')
        ->and($payload['title'])->toBe('Tiny Racer')
        ->and($payload['summary'])->toBe('A small racer for the LAN.')
        ->and($payload['version'])->toBe('1.4')
        ->and($payload['platforms'])->toBe(['windows'])
        ->and($payload['via'])->toBe('web')
        ->and($payload['license'])->toBe(PortableRecipeBuilder::LICENSE)
        ->and($payload)->not->toHaveKey('homepage')
        ->and($payload['requires'])->toHaveCount(1)
        ->and($payload['requires'][0]['role'])->toBe('base')
        ->and($payload['requires'][0]['required'])->toBeTrue()
        ->and($payload['requires'][0]['shared_file_id'])->toBe(17)
        ->and($payload['requires'][0]['size_bytes'])->toBe(987_654)
        ->and($payload['requires'][0]['sha256'])->toBe(str_repeat('a', 64))
        ->and($payload['requires'][0]['match'])->toBe([$archive])
        ->and($payload['requires'][0]['label'])->toBe('Tiny Racer (portable archive)')
        ->and($payload['requires'][0]['notes'])->toContain('{{target}}')
        ->and($payload['metadata']['portable'])->toBeTrue()
        ->and($payload['metadata']['genre'])->toBe('other')
        ->and($payload['metadata']['lan_recommended'])->toBeTrue()
        ->and($payload['metadata']['tags'])->toBe(['shared'])
        ->and($payload['metadata']['multiplayer'])->toBeTrue()
        ->and($payload['metadata']['players'])->toBe('1+')
        ->and($payload['launch_targets'])->toHaveCount(1)
        ->and($payload['launch_targets'][0]['os'])->toBe('windows')
        ->and($payload['launch_targets'][0]['shell'])->toBe('powershell')
        ->and($payload['launch_targets'][0]['command'])->toBe(
            'Start-Process -FilePath "{{target}}/bin/TinyRacer.exe" -WorkingDirectory "{{target}}"',
        );
})->with([
    'zip' => 'game-portable.zip',
    'tar.bz2' => 'game-portable.tar.bz2',
]);

it('generates a summary and a description that says the draft needs verification', function () {
    $payload = app(PortableRecipeBuilder::class)->build(
        gameShareInput(),
        gameShareArchive('game-portable.zip'),
    );

    expect($payload['summary'])->not->toBe('')
        ->and(mb_strlen($payload['summary']))->toBeLessThanOrEqual(200)
        ->and($payload['summary'])->toContain('Tiny Racer')
        ->and($payload['description'])->toContain('LANoMAT file host')
        ->and($payload['description'])->toContain('user-supplied')
        ->and($payload['description'])->toContain('needs verification on the LAN')
        ->and($payload['version'])->toBe('1.0')
        ->and($payload['homepage'] ?? null)->toBeNull();
});

it('turns glob metacharacters in the archive name into a matching glob', function () {
    $payload = app(PortableRecipeBuilder::class)->build(
        gameShareInput(),
        gameShareArchive('Game [beta]*?.zip'),
    );

    expect($payload['requires'][0]['match'])->toBe(['Game *beta***.zip']);
});

it('builds one unpack step per selected platform with a single command per entry', function () {
    $payload = app(PortableRecipeBuilder::class)->build(
        gameShareInput(['platforms' => ['windows', 'linux'], 'linux_runner' => 'native', 'linux_executable' => 'bin/tiny-racer']),
        gameShareArchive('tiny-racer.tar.bz2'),
    );

    $ids = array_column($payload['steps'], 'id');

    expect($ids)->toBe(['get-archive', 'unpack-windows', 'unpack-linux', 'check-install'])
        ->and(count($payload['steps']))->toBeGreaterThanOrEqual(3)
        ->and(count($payload['steps']))->toBeLessThanOrEqual(6);

    $byId = array_column($payload['steps'], null, 'id');
    $linuxCommands = array_column($byId['unpack-linux']['commands'], 'run');

    expect($linuxCommands)->toBe([
        'mkdir -p "{{target}}"',
        'tar -xjf \'{{downloads}}/tiny-racer.tar.bz2\' -C "{{target}}"',
    ])
        ->and($byId['unpack-windows']['commands'][0]['shell'])->toBe('powershell')
        ->and($byId['unpack-windows']['commands'][0]['run'])->toContain('Expand-Archive -LiteralPath')
        ->and($byId['unpack-windows']['commands'][0]['run'])->toContain('-DestinationPath "{{target}}"')
        ->and($byId['unpack-windows']['verify'][0]['path'])->toBe('{{target}}/bin/TinyRacer.exe')
        ->and($byId['unpack-linux']['verify'][0]['path'])->toBe('{{target}}/bin/tiny-racer')
        ->and($byId['check-install']['os'])->toBe('any')
        ->and($byId['check-install']['verify'])->toHaveCount(2);

    foreach ($payload['steps'] as $step) {
        expect(trim((string) $step['detail']))->not->toBe('');

        foreach ($step['commands'] ?? [] as $command) {
            expect($command['run'])->not->toContain('&&');
        }
    }
});

it('uses bsdtar for a zip archive on linux', function () {
    $payload = app(PortableRecipeBuilder::class)->build(
        gameShareInput(['platforms' => ['linux'], 'linux_runner' => 'wine']),
        gameShareArchive('tiny-racer.zip'),
    );

    $last = collect($payload['steps'])->firstWhere('id', 'unpack-linux')['commands'][1]['run'];

    expect($last)->toBe('bsdtar -xf \'{{downloads}}/tiny-racer.zip\' -C "{{target}}"')
        ->and($payload['launch_targets'][0]['id'])->toBe('linux-wine')
        ->and($payload['launch_targets'][0]['command'])->toBe('wine "{{target}}/bin/TinyRacer.exe"');
});

it('writes a complete SKILL.md with frontmatter, the sections and 60 to 150 lines', function (array $platforms, string $archive) {
    $payload = app(PortableRecipeBuilder::class)->build(
        gameShareInput(['platforms' => $platforms, 'linux_runner' => 'wine']),
        gameShareArchive($archive),
    );

    $skill = $payload['skill_markdown'];
    $lines = substr_count($skill, "\n") + 1;

    expect($lines)->toBeGreaterThanOrEqual(60)
        ->and($lines)->toBeLessThanOrEqual(150)
        ->and($skill)->toStartWith("---\nname: tiny-racer\ndescription: \"")
        ->and($skill)->toContain('## When to use')
        ->and($skill)->toContain('## What you need')
        ->and($skill)->toContain('## Questions to ask')
        ->and($skill)->toContain('## Verify')
        ->and($skill)->toContain('## Start')
        ->and($skill)->toContain('## Join a server via the LANoMAT server browser')
        ->and($skill)->toContain('## Troubleshooting')
        ->and($skill)->toContain('needs verification on the LAN')
        ->and($skill)->toContain('move the contents of that folder up into `{{target}}`')
        ->and(mb_strlen((string) $payload['skill_description']))->toBeGreaterThanOrEqual(20);
})->with([
    'windows only' => [['windows'], 'tiny-racer.zip'],
    'linux only' => [['linux'], 'tiny-racer.zip'],
    'both platforms' => [['windows', 'linux'], 'tiny-racer.tar.bz2'],
]);
