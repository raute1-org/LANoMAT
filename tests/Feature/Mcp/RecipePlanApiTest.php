<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeInstall;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Support\McpCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function planApiRecipe(): GameRecipe
{
    $recipe = GameRecipe::query()->create([
        'slug' => 'unreal-tournament-2004',
        'title' => 'Unreal Tournament 2004',
        'aliases' => ['ut2004'],
        'version' => '3374',
        'status' => RecipeStatus::Published,
        'source' => 'bundled',
        'platforms' => ['windows', 'linux'],
        'summary' => 'Arena shooter.',
        'skill_markdown' => "---\nname: ut2004\ndescription: Install UT2004.\n---\n",
        'requirements' => [
            ['role' => 'iso', 'label' => 'UT2004 DVD', 'match' => ['ut2004*.iso'], 'required' => true],
        ],
        'steps' => [
            ['id' => 'install', 'title' => 'Install base game', 'os' => 'windows', 'commands' => [], 'verify' => []],
            ['id' => 'linux', 'title' => 'Install on Linux', 'os' => 'linux', 'commands' => [], 'verify' => []],
        ],
        'metadata' => [
            'launch_targets' => [
                ['id' => 'flat-windows', 'label' => 'Flat', 'os' => 'windows', 'shell' => 'powershell', 'command' => 'Start-Process "{{target}}/System/UT2004.exe"'],
                ['id' => 'flat-linux', 'label' => 'Flat', 'os' => 'linux', 'shell' => 'bash', 'command' => '"{{target}}/System/ut2004-bin-amd64"'],
            ],
        ],
    ]);

    $recipe->files()->create([
        'role' => RecipeFileRole::Iso,
        'label' => 'UT2004 DVD',
        'match_patterns' => ['ut2004*.iso'],
        'required' => true,
        'sort' => 0,
    ]);

    return $recipe;
}

function planApiToken(User $user): string
{
    $token = 'lmcp_'.bin2hex(random_bytes(16));

    McpToken::query()->forceCreate([
        'user_id' => $user->id,
        'name' => 'plan test',
        'token_hash' => McpCodes::hash($token),
        'abilities' => null,
        'last_used_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    return $token;
}

it('returns the real plan contract and the launch targets for the OS', function () {
    planApiRecipe();
    $token = planApiToken(User::factory()->create());

    $this->withToken($token)
        ->postJson('/api/v1/recipes/ut2004/plan', ['os' => 'linux'])
        ->assertOk()
        ->assertJsonPath('recipe.slug', 'unreal-tournament-2004')
        ->assertJsonPath('os', 'linux')
        ->assertJsonPath('recipe.launch_targets.1.id', 'flat-linux')
        ->assertJsonStructure([
            'recipe' => ['slug', 'title', 'launch_targets', 'play_url'],
            'os',
            'target_dir',
            'downloads_dir',
            'steps',
            'files' => [['requirement', 'label', 'os', 'status', 'required']],
            'downloads',
            'avatars',
            'missing',
            'generated_at',
        ]);
});

it('does not write an install row for a dry-run plan', function () {
    planApiRecipe();
    $token = planApiToken(User::factory()->create());

    $this->withToken($token)
        ->postJson('/api/v1/recipes/ut2004/plan', ['os' => 'linux', 'dry_run' => true])
        ->assertOk();

    expect(GameRecipeInstall::query()->count())->toBe(0);

    $this->withToken($token)
        ->postJson('/api/v1/recipes/ut2004/plan', ['os' => 'linux'])
        ->assertOk();

    expect(GameRecipeInstall::query()->count())->toBe(1);
});
