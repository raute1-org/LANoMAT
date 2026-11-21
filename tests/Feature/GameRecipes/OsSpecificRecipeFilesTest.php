<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Actions\SyncRecipes;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Support\BuildInstallPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * UT2004 ships one patch per platform (a Windows zip and a Linux tar.bz2) and
 * the optional portable base archive is a Windows folder. A requirement may
 * name the `os` it belongs to, and a plan must then neither offer nor require
 * the other platform's file: a Linux launcher that downloads the Windows zip
 * unpacks UT2004.exe, a Windows launcher that downloads the tarball has no
 * launchable game, and a "missing" report for the wrong OS blocks the install.
 */
function osSpecificUt2004Recipe(): GameRecipe
{
    $result = app(SyncRecipes::class)->handle(config('recipes.source'), only: 'unreal-tournament-2004');

    expect($result['skipped'])->toBe([]);

    return GameRecipe::query()->where('slug', 'unreal-tournament-2004')->sole();
}

function osSpecificPin(GameRecipe $recipe, string $label, SharedFile $shared): GameRecipeFile
{
    $entry = $recipe->files->first(static fn (GameRecipeFile $file): bool => $file->label === $label);

    expect($entry)->not->toBeNull();

    $entry->forceFill(['shared_file_id' => $shared->id])->save();

    return $entry->refresh();
}

/** @return array<string, mixed> */
function osSpecificPlan(GameRecipe $recipe, string $os): array
{
    return app(BuildInstallPlan::class)->handle($recipe->load('files'), User::factory()->create(), $os);
}

/**
 * @param  array<string, mixed>  $plan
 * @return list<string>
 */
function osSpecificLabels(array $plan): array
{
    return array_values(array_map(static fn (array $file): string => (string) $file['label'], $plan['files']));
}

/**
 * @param  array<string, mixed>  $plan
 * @return array<string, mixed>|null
 */
function osSpecificFile(array $plan, string $label): ?array
{
    foreach ($plan['files'] as $file) {
        if ($file['label'] === $label) {
            return $file;
        }
    }

    return null;
}

/** @return array{iso: SharedFile, windows: SharedFile, linux: SharedFile, base: SharedFile} */
function osSpecificLanFiles(): array
{
    $event = Event::factory()->registration()->create();

    return [
        'iso' => SharedFile::factory()->approved()->create([
            'event_id' => $event->id,
            'original_name' => 'ut2004-dvd1.iso',
        ]),
        'windows' => SharedFile::factory()->approved()->create([
            'event_id' => $event->id,
            'original_name' => 'OldUnreal-UT2004Patch3374-Windows-d906d39a.zip',
        ]),
        'linux' => SharedFile::factory()->approved()->create([
            'event_id' => $event->id,
            'original_name' => 'OldUnreal-UT2004Patch3374-Linux-fc0484d6.tar.bz2',
        ]),
        'base' => SharedFile::factory()->approved()->create([
            'event_id' => $event->id,
            'original_name' => 'ut2004-portable-3374.zip',
        ]),
    ];
}

const OS_ISO_LABEL = 'UT2004 DVD 1 (or an existing GOG/Steam install)';
const OS_WINDOWS_PATCH_LABEL = 'OldUnreal UT2004 patch 3374 (Windows x64 zip)';
const OS_LINUX_PATCH_LABEL = 'OldUnreal UT2004 patch 3374 (Linux x64 tar.bz2)';
const OS_BASE_LABEL = 'UT2004 portable 3374 (Windows x64, no install)';

it('keeps the authored requirement os on the normalized file rows', function () {
    $recipe = osSpecificUt2004Recipe();

    $osByLabel = $recipe->files->mapWithKeys(
        static fn (GameRecipeFile $file): array => [$file->label => $file->os()],
    )->all();

    expect($osByLabel[OS_WINDOWS_PATCH_LABEL])->toBe('windows')
        ->and($osByLabel[OS_LINUX_PATCH_LABEL])->toBe('linux')
        ->and($osByLabel[OS_BASE_LABEL])->toBe('windows')
        // The DVD image carries both platforms: no `os`, so both plans see it.
        ->and($osByLabel[OS_ISO_LABEL])->toBeNull();
});

it('plans the linux patch and no windows file for a linux install', function () {
    $recipe = osSpecificUt2004Recipe();
    $lan = osSpecificLanFiles();

    osSpecificPin($recipe, OS_ISO_LABEL, $lan['iso']);
    osSpecificPin($recipe, OS_WINDOWS_PATCH_LABEL, $lan['windows']);
    osSpecificPin($recipe, OS_LINUX_PATCH_LABEL, $lan['linux']);
    osSpecificPin($recipe, OS_BASE_LABEL, $lan['base']);

    $plan = osSpecificPlan($recipe, 'linux');
    $labels = osSpecificLabels($plan);
    $patch = osSpecificFile($plan, OS_LINUX_PATCH_LABEL);
    $iso = osSpecificFile($plan, OS_ISO_LABEL);

    expect($plan['os'])->toBe('linux')
        ->and($patch)->not->toBeNull()
        ->and($patch['os'])->toBe('linux')
        ->and($patch['status'])->toBe('matched')
        ->and($patch['shared_file_id'])->toBe($lan['linux']->id)
        ->and($iso)->not->toBeNull()
        ->and($iso['os'])->toBeNull()
        ->and($iso['status'])->toBe('matched')
        // The Windows zip patch, the Windows-only portable base and the
        // Windows VR mod never reach a Linux plan, even when they are on the LAN.
        ->and($labels)->not->toContain(OS_WINDOWS_PATCH_LABEL)
        ->and($labels)->not->toContain(OS_BASE_LABEL)
        ->and($labels)->not->toContain('UT2004VR (optional SteamVR mod, Windows only)')
        ->and($plan['missing'])->toBe([])
        ->and(array_column($plan['downloads'], 'label'))->toBe([OS_ISO_LABEL, OS_LINUX_PATCH_LABEL]);
});

it('plans the windows patch and the portable base for a windows install', function () {
    $recipe = osSpecificUt2004Recipe();
    $lan = osSpecificLanFiles();

    osSpecificPin($recipe, OS_ISO_LABEL, $lan['iso']);
    osSpecificPin($recipe, OS_WINDOWS_PATCH_LABEL, $lan['windows']);
    osSpecificPin($recipe, OS_LINUX_PATCH_LABEL, $lan['linux']);
    osSpecificPin($recipe, OS_BASE_LABEL, $lan['base']);

    $plan = osSpecificPlan($recipe, 'windows');
    $labels = osSpecificLabels($plan);
    $patch = osSpecificFile($plan, OS_WINDOWS_PATCH_LABEL);
    $base = osSpecificFile($plan, OS_BASE_LABEL);

    expect($plan['os'])->toBe('windows')
        ->and($patch)->not->toBeNull()
        ->and($patch['os'])->toBe('windows')
        ->and($patch['status'])->toBe('matched')
        ->and($patch['shared_file_id'])->toBe($lan['windows']->id)
        ->and($base)->not->toBeNull()
        ->and($base['os'])->toBe('windows')
        ->and($base['status'])->toBe('matched')
        ->and(osSpecificFile($plan, OS_ISO_LABEL))->not->toBeNull()
        ->and($labels)->not->toContain(OS_LINUX_PATCH_LABEL)
        ->and($plan['missing'])->toBe([]);
});

it('treats any, empty and an unknown os as no restriction', function () {
    foreach (['any', '', 'amiga', 'WINDOWS'] as $index => $stored) {
        $file = GameRecipeFile::factory()->create(['label' => 'File '.$index]);
        $file->forceFill(['metadata' => ['os' => $stored]])->save();

        // Only a known platform narrows a file: a typo never hides a
        // required file from every plan.
        expect($file->refresh()->os())->toBe($stored === 'WINDOWS' ? 'windows' : null);
    }
});

it('reports only the missing patch of the requested os', function () {
    $recipe = osSpecificUt2004Recipe();

    $iso = SharedFile::factory()->approved()->create([
        'event_id' => Event::factory()->registration()->create()->id,
        'original_name' => 'ut2004-dvd1.iso',
    ]);

    osSpecificPin($recipe, OS_ISO_LABEL, $iso);

    $linux = osSpecificPlan($recipe, 'linux');
    $windows = osSpecificPlan($recipe, 'windows');

    // A patch requirement names the OldUnreal release page, so an unmatched
    // one is reported as an external download — but only for its own OS.
    expect($linux['missing'])->toBe([OS_LINUX_PATCH_LABEL.' (external download only)'])
        ->and(osSpecificLabels($linux))->not->toContain(OS_WINDOWS_PATCH_LABEL)
        ->and($windows['missing'])->toBe([OS_WINDOWS_PATCH_LABEL.' (external download only)'])
        ->and(osSpecificLabels($windows))->not->toContain(OS_LINUX_PATCH_LABEL);
});
