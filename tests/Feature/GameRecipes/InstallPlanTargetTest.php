<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\GameRecipes\Actions\SyncRecipes;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\BuildInstallPlan;
use App\Modules\GameRecipes\Support\LauncherSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The plan's target folder is where the launcher unpacks a game on the
 * player's disk. Every macOS recipe used to land in UT2004's Application
 * Support folder; only the OldUnreal bundle actually reads its assets there.
 */
function installPlanTargetFor(GameRecipe $recipe, string $os): string
{
    $plan = app(BuildInstallPlan::class)->handle($recipe->load('files'), User::factory()->create(), $os, record: false);

    return $plan['target_dir'];
}

it('keeps the Application Support folder for the UT2004 pilot on macOS', function () {
    app(SyncRecipes::class)->handle(config('recipes.source'), only: LauncherSupport::PILOT_SLUG);

    $recipe = GameRecipe::query()->where('slug', LauncherSupport::PILOT_SLUG)->sole();

    expect(installPlanTargetFor($recipe, 'macos'))->toBe('~/Library/Application Support/UT2004')
        // The pilot's other platforms keep their own folders.
        ->and(installPlanTargetFor($recipe, 'linux'))->toBe('~/Games/unreal-tournament-2004')
        ->and(installPlanTargetFor($recipe, 'windows'))->toBe('C:\\Games\\Unreal Tournament 2004');
});

it('gives two different recipes two different macOS folders', function () {
    $first = GameRecipe::factory()->create(['slug' => 'first-game', 'title' => 'First Game']);
    $second = GameRecipe::factory()->create(['slug' => 'second-game', 'title' => 'Second Game']);

    $firstTarget = installPlanTargetFor($first, 'macos');
    $secondTarget = installPlanTargetFor($second, 'macos');

    expect($firstTarget)->toBe('~/Games/first-game')
        ->and($secondTarget)->toBe('~/Games/second-game')
        ->and($firstTarget)->not->toBe($secondTarget)
        // Never the pilot's hard-coded folder.
        ->and($firstTarget)->not->toContain('Application Support');
});

it('builds the Windows and Linux folders as before', function () {
    $recipe = GameRecipe::factory()->create(['slug' => 'some-game', 'title' => 'Some Game']);

    expect(installPlanTargetFor($recipe, 'windows'))->toBe('C:\\Games\\Some Game')
        ->and(installPlanTargetFor($recipe, 'linux'))->toBe('~/Games/some-game')
        ->and(installPlanTargetFor($recipe, 'linux'))->toBe(installPlanTargetFor($recipe, 'macos'));
});

it('keeps a title of separators and dots out of the folder name', function () {
    $recipe = GameRecipe::factory()->create(['slug' => 'traversal-game', 'title' => '../../etc/passwd']);
    $dots = GameRecipe::factory()->create(['slug' => 'dot-game', 'title' => '../..']);

    foreach (['windows', 'linux', 'macos'] as $os) {
        $target = installPlanTargetFor($recipe, $os);

        expect($target)->not->toContain('..')
            ->and($target)->not->toContain('etc/')
            ->and($target)->toContain('etcpasswd');
    }

    // A title that is nothing but separators falls back to the recipe slug.
    expect(installPlanTargetFor($dots, 'macos'))->toBe('~/Games/dot-game')
        ->and(installPlanTargetFor($dots, 'linux'))->toBe('~/Games/dot-game')
        ->and(installPlanTargetFor($dots, 'windows'))->toBe('C:\\Games\\dot-game');
});
