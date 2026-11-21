<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Support\BuildInstallPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const STORED_SHA256 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const PINNED_SHA256 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

/**
 * A published recipe with one required ISO, matched to an approved LAN upload
 * that carries `$storedSha256`. The recipe entry may pin its own hash.
 *
 * @return array<string, mixed>
 */
function installPlanForSha256(?string $storedSha256, ?string $pinnedSha256): array
{
    $event = Event::factory()->registration()->create();

    SharedFile::factory()->approved()->create([
        'event_id' => $event->id,
        'original_name' => 'unreal.iso',
        'size_bytes' => 4096,
        'sha256' => $storedSha256,
    ]);

    $recipe = GameRecipe::factory()->published()->create();

    GameRecipeFile::factory()->for($recipe, 'recipe')->create([
        'role' => RecipeFileRole::Iso,
        'label' => 'Disc',
        'match_patterns' => ['*.iso'],
        'required' => true,
        'sha256' => $pinnedSha256,
    ]);

    return app(BuildInstallPlan::class)->handle($recipe->load('files'), User::factory()->create(), 'windows');
}

/**
 * @param  array<string, mixed>  $plan
 * @return array<string, mixed>|null
 */
function matchedPlanFile(array $plan): ?array
{
    /** @var array<int, array<string, mixed>> $files */
    $files = $plan['files'];

    foreach ($files as $file) {
        if (($file['status'] ?? null) === 'matched') {
            return $file;
        }
    }

    return null;
}

it('exposes the stored server-side sha256 for a matched LAN file without a recipe pin', function () {
    $plan = installPlanForSha256(STORED_SHA256, null);

    /** @var array<int, array<string, mixed>> $downloads */
    $downloads = $plan['downloads'];

    expect(matchedPlanFile($plan)['sha256'] ?? null)->toBe(STORED_SHA256)
        ->and($downloads[0]['sha256'])->toBe(STORED_SHA256);
});

it('prefers the recipe pin over the stored sha256', function () {
    $plan = installPlanForSha256(STORED_SHA256, PINNED_SHA256);

    expect(matchedPlanFile($plan)['sha256'] ?? null)->toBe(PINNED_SHA256);
});

it('keeps sha256 null when neither the recipe nor the stored file has one', function () {
    $plan = installPlanForSha256(null, null);

    $file = matchedPlanFile($plan);

    expect($file)->not->toBeNull()
        ->and($file['sha256'] ?? null)->toBeNull();
});
