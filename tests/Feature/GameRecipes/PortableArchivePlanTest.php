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
 * The UT2004 recipe may offer a portable, already-patched folder archive next
 * to the DVD image. It is optional: without it the ISO plan must stay exactly
 * as before, and the launcher only prefers it when it is actually on the LAN.
 */
function ut2004PortableRecipe(): GameRecipe
{
    $result = app(SyncRecipes::class)->handle(config('recipes.source'), only: 'unreal-tournament-2004');

    expect($result['skipped'])->toBe([])
        ->and($result['created'])->toBe(1);

    return GameRecipe::query()->where('slug', 'unreal-tournament-2004')->sole();
}

function ut2004PinRequirement(GameRecipe $recipe, string $role, SharedFile $shared): GameRecipeFile
{
    $entry = $recipe->files->first(
        static fn (GameRecipeFile $file): bool => $file->role === RecipeFileRole::from($role),
    );

    expect($entry)->not->toBeNull();

    $entry->forceFill(['shared_file_id' => $shared->id])->save();

    return $entry->refresh();
}

/**
 * Mirrors the launcher's `InstallEngine::base_file` priority without needing
 * the Rust toolchain: a matched archive/base beats a matched ISO
 * (clients/launcher/launcher-core/src/install.rs).
 *
 * @param  array<string, mixed>  $plan
 */
function ut2004LauncherBaseRequirement(array $plan): ?string
{
    foreach (['archive', 'base', 'iso'] as $requirement) {
        foreach ($plan['files'] as $file) {
            if ($file['requirement'] === $requirement && $file['status'] === 'matched') {
                return $file['requirement'];
            }
        }
    }

    return null;
}

it('plans the portable archive as the base when the orga provides it', function () {
    $recipe = ut2004PortableRecipe();

    $shared = SharedFile::factory()->approved()->create([
        'event_id' => Event::factory()->registration()->create()->id,
        'original_name' => 'ut2004-portable-3374.zip',
    ]);

    ut2004PinRequirement($recipe, 'base', $shared);

    $plan = app(BuildInstallPlan::class)->handle($recipe->load('files'), User::factory()->create(), 'windows');

    $base = collect($plan['files'])->firstWhere('requirement', 'base');

    expect($base)->not->toBeNull()
        ->and($base['status'])->toBe('matched')
        ->and($base['shared_file_id'])->toBe($shared->id)
        ->and($base['name'])->toBe('ut2004-portable-3374.zip')
        ->and($base['required'])->toBeFalse()
        ->and(ut2004LauncherBaseRequirement($plan))->toBe('base');
});

it('keeps the ISO plan when only the disc is on the LAN', function () {
    $recipe = ut2004PortableRecipe();

    $shared = SharedFile::factory()->approved()->create([
        'event_id' => Event::factory()->registration()->create()->id,
        'original_name' => 'ut2004-dvd1.iso',
    ]);

    ut2004PinRequirement($recipe, 'iso', $shared);

    $plan = app(BuildInstallPlan::class)->handle($recipe->load('files'), User::factory()->create(), 'windows');

    $iso = collect($plan['files'])->firstWhere('requirement', 'iso');
    $base = collect($plan['files'])->firstWhere('requirement', 'base');

    expect($iso)->not->toBeNull()
        ->and($iso['status'])->toBe('matched')
        ->and($iso['shared_file_id'])->toBe($shared->id)
        ->and($base['status'])->not->toBe('matched')
        ->and(ut2004LauncherBaseRequirement($plan))->toBe('iso');
});

it('still offers the ISO when both are on the LAN and lets the launcher prefer the archive', function () {
    $recipe = ut2004PortableRecipe();

    $event = Event::factory()->registration()->create();

    $portable = SharedFile::factory()->approved()->create([
        'event_id' => $event->id,
        'original_name' => 'UT2004-portable-3374.zip',
    ]);
    $iso = SharedFile::factory()->approved()->create([
        'event_id' => $event->id,
        'original_name' => 'ut2004-dvd1.iso',
    ]);

    ut2004PinRequirement($recipe, 'base', $portable);
    ut2004PinRequirement($recipe, 'iso', $iso);

    $plan = app(BuildInstallPlan::class)->handle($recipe->load('files'), User::factory()->create(), 'windows');

    $base = collect($plan['files'])->firstWhere('requirement', 'base');

    expect($base['status'])->toBe('matched')
        ->and($base['shared_file_id'])->toBe($portable->id)
        ->and(collect($plan['files'])->firstWhere('requirement', 'iso')['status'])->toBe('matched')
        // The launcher contract: archive/base before iso, so the portable
        // folder wins while the ISO stays available as a fallback download.
        ->and(ut2004LauncherBaseRequirement($plan))->toBe('base');
});
