<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Support\BuildInstallPlan;
use App\Modules\GameRecipes\Support\InstallPlanUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function planWithLanFile(?InstallPlanUrls $urls = null): array
{
    $event = Event::factory()->registration()->create();
    $shared = SharedFile::factory()->approved()->create(['event_id' => $event->id, 'original_name' => 'game.zip', 'size_bytes' => 10]);
    $recipe = GameRecipe::factory()->published()->create();
    GameRecipeFile::factory()->for($recipe, 'recipe')->create([
        'role' => RecipeFileRole::Base, 'label' => 'Base', 'match_patterns' => ['game.zip'], 'required' => true,
    ]);

    return [$shared, app(BuildInstallPlan::class)->handle($recipe->load('files'), User::factory()->create(), 'windows', null, true, $urls)];
}

it('keeps the MCP link and the exact historic file shape by default', function () {
    [$shared, $plan] = planWithLanFile();
    $file = collect($plan['files'])->firstWhere('status', 'matched');

    expect($file['download_url'])->toBe(route('mcp.files.download', ['sharedFile' => $shared->id], true))
        ->and($file)->not->toHaveKey('download_expires_at')
        ->and($plan)->not->toHaveKey('plan_version');
});

it('uses whatever the injected strategy mints for files and avatars', function () {
    $strategy = new class implements InstallPlanUrls
    {
        public function forFile(SharedFile $file, User $viewer): array
        {
            return ['url' => 'https://files.example.test/signed/'.$file->id, 'expires_at' => '2026-11-22T12:00:00+00:00'];
        }
    };

    [$shared, $plan] = planWithLanFile($strategy);
    $file = collect($plan['files'])->firstWhere('status', 'matched');

    expect($file['download_url'])->toBe('https://files.example.test/signed/'.$shared->id)
        ->and($file['download_expires_at'])->toBe('2026-11-22T12:00:00+00:00');
});
