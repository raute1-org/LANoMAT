<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Actions\ExportRecipes;
use App\Modules\GameRecipes\Actions\SyncRecipes;
use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
    // Prune needs at least one recipe directory left in the source: an empty
    // source must not look like a deleted catalogue (see the empty-source test).
    writeRecipe($dir, 'other-game');
    $third = $sync->handle($dir, prune: true);

    expect($third['pruned'])->toBe(1)
        ->and(GameRecipe::query()->pluck('slug')->all())->toBe(['other-game']);

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

it('keeps the recipes it did not select for updating when --only prunes', function () {
    $dir = sys_get_temp_dir().'/lanomat-sync-'.bin2hex(random_bytes(4));
    writeRecipe($dir, 'game-a');
    writeRecipe($dir, 'game-b');

    $sync = app(SyncRecipes::class);
    $sync->handle($dir);

    expect(GameRecipe::query()->count())->toBe(2);

    $result = $sync->handle($dir, prune: true, only: 'game-a');

    expect($result['pruned'])->toBe(0)
        ->and($result['updated'])->toBe(1)
        ->and($result['skipped'])->toBe([])
        ->and(GameRecipe::query()->orderBy('slug')->pluck('slug')->all())->toBe(['game-a', 'game-b']);

    File::deleteDirectory($dir);
});

it('keeps a recipe whose file fails validation or no longer parses', function () {
    $dir = sys_get_temp_dir().'/lanomat-sync-'.bin2hex(random_bytes(4));
    writeRecipe($dir, 'game-a');
    writeRecipe($dir, 'game-b');
    writeRecipe($dir, 'game-c');

    $sync = app(SyncRecipes::class);
    $sync->handle($dir);

    // A schema error (still readable YAML) and a file that is not YAML at all.
    File::put($dir.'/game-b/recipe.yaml', Yaml::dump(['schema' => 'wrong', 'slug' => 'game-b']));
    File::put($dir.'/game-c/recipe.yaml', "slug: game-c\ntitle: [unclosed\n");

    $result = $sync->handle($dir, prune: true, only: 'game-a');

    expect($result['pruned'])->toBe(0)
        ->and($result['skipped'])->toHaveCount(2)
        ->and(GameRecipe::query()->orderBy('slug')->pluck('slug')->all())->toBe(['game-a', 'game-b', 'game-c']);

    File::deleteDirectory($dir);
});

it('prunes only this source kind and only slugs whose recipe directory is gone', function () {
    $dir = sys_get_temp_dir().'/lanomat-sync-'.bin2hex(random_bytes(4));
    writeRecipe($dir, 'game-a');

    $sync = app(SyncRecipes::class);
    $sync->handle($dir);

    $contribution = GameRecipe::factory()
        ->contributedBy(User::factory()->create())
        ->create(['slug' => 'shared-game']);
    $panel = GameRecipe::factory()->create(['slug' => 'panel-game', 'source' => GameRecipe::SOURCE_PANEL]);
    $gone = GameRecipe::factory()->create(['slug' => 'gone-game', 'source' => 'bundled']);

    $result = $sync->handle($dir, prune: true);

    expect($result['pruned'])->toBe(1)
        ->and(GameRecipe::query()->whereKey($gone->getKey())->exists())->toBeFalse()
        ->and(GameRecipe::query()->whereKey($contribution->getKey())->exists())->toBeTrue()
        ->and(GameRecipe::query()->whereKey($panel->getKey())->exists())->toBeTrue()
        ->and(GameRecipe::query()->where('slug', 'game-a')->exists())->toBeTrue();

    File::deleteDirectory($dir);
});

it('does not prune anything when the source has no recipe directory', function () {
    $dir = sys_get_temp_dir().'/lanomat-sync-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($dir);

    $kept = GameRecipe::factory()->create(['slug' => 'kept-game', 'source' => 'bundled']);

    $result = app(SyncRecipes::class)->handle($dir, prune: true);

    expect($result['pruned'])->toBe(0)
        ->and($result['created'])->toBe(0)
        ->and($result['skipped'])->toHaveCount(1)
        ->and($result['skipped'][0])->toContain('no recipe')
        ->and(GameRecipe::query()->whereKey($kept->getKey())->exists())->toBeTrue();

    $exitCode = Artisan::call('lanomat:recipes:sync', ['source' => $dir, '--prune' => true, '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($decoded)->toBeArray()
        ->and($decoded['pruned'])->toBe(0)
        ->and($decoded['skipped'])->toHaveCount(1)
        ->and(GameRecipe::query()->whereKey($kept->getKey())->exists())->toBeTrue();

    File::deleteDirectory($dir);
});

it('warns when --only names a slug the source does not have', function () {
    $dir = sys_get_temp_dir().'/lanomat-sync-'.bin2hex(random_bytes(4));
    writeRecipe($dir, 'game-a');

    $result = app(SyncRecipes::class)->handle($dir, only: 'missing-game');

    expect($result['created'])->toBe(0)
        ->and($result['updated'])->toBe(0)
        ->and($result['skipped'])->toHaveCount(1)
        ->and($result['skipped'][0])->toContain('missing-game')
        ->and(GameRecipe::query()->count())->toBe(0);

    $exitCode = Artisan::call('lanomat:recipes:sync', [
        'source' => $dir,
        '--only' => 'missing-game',
        '--prune' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($decoded)->toBeArray()
        ->and(array_keys($decoded))->toBe(['created', 'updated', 'pruned', 'skipped', 'source'])
        ->and($decoded['skipped'][0])->toContain('missing-game')
        ->and(GameRecipe::query()->count())->toBe(0);

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
