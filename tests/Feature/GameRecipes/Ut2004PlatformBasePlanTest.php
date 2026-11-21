<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Actions\SyncRecipes;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Support\BuildInstallPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * UT2004 is one-click on Windows, Linux and macOS: the preferred base is a
 * portable 3374 archive per OS, the ISO/DMG path is the fallback, and a macOS
 * plan must never pick the Windows or Linux archives.
 */
const UT2004_ISO_LABEL = 'UT2004 DVD 1 (or an existing GOG/Steam install)';
const UT2004_WINDOWS_BASE_LABEL = 'UT2004 portable 3374 (Windows x64, no install)';
const UT2004_LINUX_BASE_LABEL = 'UT2004 portable 3374 (Linux x64, no install)';
const UT2004_MACOS_BASE_LABEL = 'UT2004 portable 3374 (macOS universal, no install)';
const UT2004_MACOS_PATCH_LABEL = 'OldUnreal UT2004 patch 3374 (macOS universal dmg)';

function ut2004PlatformRecipe(): GameRecipe
{
    $result = app(SyncRecipes::class)->handle(config('recipes.source'), only: 'unreal-tournament-2004');

    expect($result['skipped'])->toBe([]);

    return GameRecipe::query()->where('slug', 'unreal-tournament-2004')->sole();
}

function ut2004PlatformPin(GameRecipe $recipe, string $label, SharedFile $shared): GameRecipeFile
{
    $entry = $recipe->files->first(static fn (GameRecipeFile $file): bool => $file->label === $label);

    expect($entry)->not->toBeNull();

    $entry->forceFill(['shared_file_id' => $shared->id])->save();

    return $entry->refresh();
}

/**
 * @param  array<string, mixed>  $plan
 * @return array<string, mixed>|null
 */
function ut2004PlatformFile(array $plan, string $label): ?array
{
    foreach ($plan['files'] as $file) {
        if ($file['label'] === $label) {
            return $file;
        }
    }

    return null;
}

/** @return array<string, mixed> */
function ut2004PlatformPlan(GameRecipe $recipe, string $os): array
{
    return app(BuildInstallPlan::class)->handle($recipe->load('files'), User::factory()->create(), $os);
}

it('ships the macOS requirement, steps and launch target in the bundled recipe', function () {
    $recipe = ut2004PlatformRecipe();

    $patch = $recipe->files->first(static fn (GameRecipeFile $file): bool => $file->label === UT2004_MACOS_PATCH_LABEL);

    expect($recipe->platforms)->toContain('macos')
        ->and($patch)->not->toBeNull()
        ->and($patch->role)->toBe(RecipeFileRole::Patch)
        ->and($patch->os())->toBe('macos')
        ->and($patch->url)->toBe('https://github.com/OldUnreal/UT2004Patches/releases/download/3374-rc3/OldUnreal-UT2004Patch3374-macOS.dmg')
        ->and($patch->sha256)->toBe('8c9bd030127c2fd37c2565aad59cb5f4dbb696909e01c1cb975aecf7e38b8e7c')
        ->and($patch->size_bytes)->toBe(61235839);

    $steps = collect($recipe->steps)->keyBy('id');

    expect($steps['install-base-macos']['os'])->toBe('macos')
        ->and($steps['patch-3374-macos']['os'])->toBe('macos');

    $target = collect($recipe->metadata['launch_targets'] ?? [])->firstWhere('id', 'flat-macos');

    expect($target)->not->toBeNull()
        ->and($target['os'])->toBe('macos');
});

it('plans the portable base of the requested os and no other platform archive', function () {
    $recipe = ut2004PlatformRecipe();
    $event = Event::factory()->registration()->create();

    $files = [
        'iso' => SharedFile::factory()->approved()->create([
            'event_id' => $event->id,
            'original_name' => 'ut2004-dvd1.iso',
        ]),
        'windows' => SharedFile::factory()->approved()->create([
            'event_id' => $event->id,
            'original_name' => 'ut2004-portable-3374.zip',
        ]),
        'linux' => SharedFile::factory()->approved()->create([
            'event_id' => $event->id,
            'original_name' => 'ut2004-portable-3374-linux.tar.bz2',
        ]),
        'macos' => SharedFile::factory()->approved()->create([
            'event_id' => $event->id,
            'original_name' => 'ut2004-portable-3374-macos.zip',
        ]),
        'macos-patch' => SharedFile::factory()->approved()->create([
            'event_id' => $event->id,
            'original_name' => 'OldUnreal-UT2004Patch3374-macOS.dmg',
        ]),
    ];

    ut2004PlatformPin($recipe, UT2004_ISO_LABEL, $files['iso']);
    ut2004PlatformPin($recipe, UT2004_WINDOWS_BASE_LABEL, $files['windows']);
    ut2004PlatformPin($recipe, UT2004_LINUX_BASE_LABEL, $files['linux']);
    ut2004PlatformPin($recipe, UT2004_MACOS_BASE_LABEL, $files['macos']);
    ut2004PlatformPin($recipe, UT2004_MACOS_PATCH_LABEL, $files['macos-patch']);

    $windows = ut2004PlatformPlan($recipe, 'windows');
    $linux = ut2004PlatformPlan($recipe, 'linux');
    $macos = ut2004PlatformPlan($recipe, 'macos');

    expect(ut2004PlatformFile($windows, UT2004_WINDOWS_BASE_LABEL)['shared_file_id'])->toBe($files['windows']->id)
        ->and(ut2004PlatformFile($windows, UT2004_LINUX_BASE_LABEL))->toBeNull()
        ->and(ut2004PlatformFile($windows, UT2004_MACOS_BASE_LABEL))->toBeNull()
        ->and(ut2004PlatformFile($linux, UT2004_LINUX_BASE_LABEL)['shared_file_id'])->toBe($files['linux']->id)
        ->and(ut2004PlatformFile($linux, UT2004_WINDOWS_BASE_LABEL))->toBeNull()
        ->and(ut2004PlatformFile($linux, UT2004_MACOS_BASE_LABEL))->toBeNull()
        ->and(ut2004PlatformFile($macos, UT2004_MACOS_BASE_LABEL)['shared_file_id'])->toBe($files['macos']->id)
        ->and(ut2004PlatformFile($macos, UT2004_WINDOWS_BASE_LABEL))->toBeNull()
        ->and(ut2004PlatformFile($macos, UT2004_LINUX_BASE_LABEL))->toBeNull()
        // The fallback macOS patch is in the plan and matched from the LAN.
        ->and(ut2004PlatformFile($macos, UT2004_MACOS_PATCH_LABEL)['status'])->toBe('matched')
        ->and(ut2004PlatformFile($macos, UT2004_MACOS_PATCH_LABEL)['shared_file_id'])->toBe($files['macos-patch']->id)
        // The macOS step path is offered on macOS only.
        ->and(array_column($macos['steps'], 'id'))->toContain('install-base-macos', 'patch-3374-macos')
        ->and(array_column($linux['steps'], 'id'))->not->toContain('patch-3374-macos');
});
