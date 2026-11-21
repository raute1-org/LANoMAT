<?php

declare(strict_types=1);

use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Actions\ExportRecipes;
use App\Modules\GameRecipes\Actions\SyncRecipes;
use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

function writeRecipe(string $dir, string $slug, array $overrides = []): string
{
    $path = $dir.'/'.$slug;
    File::ensureDirectoryExists($path);

    $yaml = [
        'schema' => 'lanomat.recipe/v1',
        'slug' => $slug,
        'title' => $overrides['title'] ?? 'Test Game',
        'aliases' => ['test'],
        'version' => '1.0',
        'status' => $overrides['status'] ?? 'published',
        'platforms' => ['windows'],
        'summary' => 'A test recipe.',
        'skill' => ['name' => $slug, 'description' => 'Install the test game from the LAN when the user asks for it.'],
        'requirements' => [
            ['role' => 'iso', 'label' => 'Disc', 'match' => ['test*.iso'], 'required' => true],
            ['role' => 'avatar', 'label' => 'Avatar', 'match' => ['*.vrm'], 'required' => false],
        ],
        'steps' => [
            ['id' => 'install', 'title' => 'Install', 'os' => 'windows', 'commands' => [['shell' => 'powershell', 'run' => 'setup.exe']]],
        ],
    ];

    File::put($path.'/recipe.yaml', Yaml::dump($yaml, 10));
    File::put($path.'/SKILL.md', "---\nname: {$slug}\ndescription: \"Install the test game.\"\n---\n# Test\n");

    return $path;
}

it('imports a recipe directory, updates it and prunes it', function () {
    $dir = sys_get_temp_dir().'/lanomat-sync-'.bin2hex(random_bytes(4));
    writeRecipe($dir, 'test-game');

    $sync = app(SyncRecipes::class);

    $first = $sync->handle($dir, prune: true);

    expect($first['created'])->toBe(1)
        ->and($first['skipped'])->toBe([])
        ->and(GameRecipe::query()->where('slug', 'test-game')->exists())->toBeTrue();

    $recipe = GameRecipe::query()->where('slug', 'test-game')->sole();

    expect($recipe->files)->toHaveCount(2)
        ->and($recipe->files->first()->role->value)->toBe('iso')
        ->and($recipe->skill_markdown)->toContain('name: test-game');

    writeRecipe($dir, 'test-game', ['title' => 'Test Game 2']);
    $second = $sync->handle($dir);

    expect($second['updated'])->toBe(1)
        ->and(GameRecipe::query()->where('slug', 'test-game')->sole()->title)->toBe('Test Game 2');

    File::deleteDirectory($dir.'/test-game');
    $third = $sync->handle($dir, prune: true);

    expect($third['pruned'])->toBe(1)
        ->and(GameRecipe::query()->count())->toBe(0);

    File::deleteDirectory($dir);
});

it('keeps a moderator decision and a pinned shared file across a resync', function () {
    $dir = sys_get_temp_dir().'/lanomat-sync-'.bin2hex(random_bytes(4));
    writeRecipe($dir, 'test-game');

    $sync = app(SyncRecipes::class);
    $sync->handle($dir);

    $recipe = GameRecipe::query()->where('slug', 'test-game')->sole();
    $recipe->forceFill(['status' => 'draft'])->save();

    $file = $recipe->files->first();
    $shared = SharedFile::factory()->approved()->create();
    $file->forceFill(['shared_file_id' => $shared->id])->save();

    $sync->handle($dir);

    expect($recipe->fresh()->status->value)->toBe('draft')
        ->and($recipe->fresh()->files->first()->shared_file_id)->toBe($shared->id);

    File::deleteDirectory($dir);
});

it('skips invalid recipes without aborting the sync', function () {
    $dir = sys_get_temp_dir().'/lanomat-sync-'.bin2hex(random_bytes(4));
    writeRecipe($dir, 'good-game');
    File::ensureDirectoryExists($dir.'/broken');
    File::put($dir.'/broken/recipe.yaml', "schema: wrong\nslug: Bad Slug\n");

    $result = app(SyncRecipes::class)->handle($dir);

    expect($result['created'])->toBe(1)
        ->and($result['skipped'])->toHaveCount(1);

    File::deleteDirectory($dir);
});

it('lifts legacy top-level play and launch fields into metadata', function () {
    $dir = sys_get_temp_dir().'/lanomat-sync-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($dir.'/legacy-game');
    File::put($dir.'/legacy-game/recipe.yaml', Yaml::dump([
        'schema' => 'lanomat.recipe/v1',
        'slug' => 'legacy-game',
        'title' => 'Legacy Game',
        'status' => 'published',
        'platforms' => ['windows', 'linux'],
        'summary' => 'A legacy recipe.',
        'skill' => ['name' => 'legacy-game', 'description' => 'Install the legacy game when the user asks for it.'],
        'requires' => [],
        'steps' => [['id' => 'install', 'title' => 'Install', 'os' => 'windows', 'commands' => [['shell' => 'powershell', 'run' => 'setup.exe']]]],
        'play_url' => 'http://{{host}}:8080/play?game=legacy',
        'launch_targets' => [
            ['id' => 'flat-windows', 'label' => 'Flat', 'os' => 'windows', 'shell' => 'powershell', 'command' => 'Start-Process "{{target}}/legacy.exe"'],
            ['id' => 'flat-linux', 'label' => 'Flat', 'os' => 'linux', 'shell' => 'bash', 'command' => '"{{target}}/legacy"'],
        ],
        'metadata' => ['genre' => 'puzzle', 'players' => '1', 'lan_recommended' => false, 'tags' => ['puzzle']],
    ], 10));
    File::put($dir.'/legacy-game/SKILL.md', '---
name: legacy-game
description: "Install the legacy game."
---
# Legacy
');

    app(SyncRecipes::class)->handle($dir);

    $recipe = GameRecipe::query()->where('slug', 'legacy-game')->sole();

    expect($recipe->playUrl('lan.example'))->toBe('http://lan.example:8080/play?game=legacy')
        ->and($recipe->launchTarget(null, 'windows'))->toBe('Start-Process "{{target}}/legacy.exe"')
        ->and($recipe->launchTarget('flat-linux'))->toBe('"{{target}}/legacy"');

    File::deleteDirectory($dir);
});

it('exports published recipes as agent skill directories', function () {
    $dir = sys_get_temp_dir().'/lanomat-sync-'.bin2hex(random_bytes(4));
    writeRecipe($dir, 'test-game');
    app(SyncRecipes::class)->handle($dir);

    $out = sys_get_temp_dir().'/lanomat-export-'.bin2hex(random_bytes(4));
    $result = app(ExportRecipes::class)->handle($out);

    expect($result['written'])->toBe(1)
        ->and(File::exists($out.'/test-game/SKILL.md'))->toBeTrue()
        ->and(File::exists($out.'/test-game/recipe.json'))->toBeTrue();

    File::deleteDirectory($dir);
    File::deleteDirectory($out);
});
