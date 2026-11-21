<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\Mcp\Server\LanomatServer;
use App\Modules\Mcp\Tools\MeTool;
use App\Modules\Mcp\Tools\RecipeAvatarsTool;
use App\Modules\Mcp\Tools\RecipeContributeTool;
use App\Modules\Mcp\Tools\RecipeGetTool;
use App\Modules\Mcp\Tools\RecipeInstallPlanTool;
use App\Modules\Mcp\Tools\RecipesListTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    // These fixtures are a full catalogue; the lineup filter has its own test.
    Config::set('launcher.lineup', '');
});

function ut2004Recipe(): GameRecipe
{
    $recipe = GameRecipe::query()->create([
        'slug' => 'unreal-tournament-2004',
        'title' => 'Unreal Tournament 2004',
        'aliases' => ['ut2004', 'ut2k4', 'ut 2k4', 'unreal tournament'],
        'version' => '3374',
        'status' => RecipeStatus::Published,
        'source' => 'bundled',
        'platforms' => ['windows', 'linux'],
        'summary' => 'Base game from the LAN disc image, optional UT2004VR mod.',
        'skill_markdown' => "---\nname: ut2004\ndescription: Install UT2004 from the LAN.\n---\n# UT2004\n",
        'requirements' => [
            ['role' => 'iso', 'label' => 'UT2004 DVD', 'match' => ['ut2004*.iso'], 'required' => true],
            ['role' => 'avatar', 'label' => 'VRM avatar · Alyx', 'match' => ['alyx*.vrm'], 'required' => false],
        ],
        'steps' => [
            ['id' => 'install', 'title' => 'Install base game', 'os' => 'windows', 'optional' => false, 'detail' => 'Mount and install.', 'commands' => [['shell' => 'powershell', 'run' => 'Start-Process setup.exe']], 'verify' => []],
            ['id' => 'vr', 'title' => 'UT2004VR', 'os' => 'windows', 'optional' => true, 'detail' => 'SteamVR mod.', 'commands' => [['shell' => 'powershell', 'run' => '& Install.bat']], 'verify' => []],
        ],
    ]);

    $recipe->files()->create([
        'role' => RecipeFileRole::Iso,
        'label' => 'UT2004 DVD',
        'match_patterns' => ['ut2004*.iso'],
        'required' => true,
        'sort' => 0,
    ]);

    $recipe->files()->create([
        'role' => RecipeFileRole::Avatar,
        'label' => 'VRM avatar · Alyx',
        'match_patterns' => ['alyx*.vrm'],
        'required' => false,
        'sort' => 1,
        'metadata' => ['author' => 'Valve', 'license' => 'CC-BY'],
    ]);

    return $recipe;
}

it('lists published recipes and hides drafts from participants', function () {
    $user = User::factory()->create();
    ut2004Recipe();
    GameRecipe::query()->create([
        'slug' => 'secret-draft',
        'title' => 'Secret',
        'status' => RecipeStatus::Draft,
        'source' => 'contribution',
        'platforms' => ['windows'],
        'steps' => [],
        'requirements' => [],
    ]);

    LanomatServer::actingAs($user);

    LanomatServer::tool(RecipesListTool::class)
        ->assertSee('unreal-tournament-2004')
        ->assertDontSee('secret-draft');
});

it('matches an uploaded ISO on the LAN and builds the install plan', function () {
    $user = User::factory()->create();
    $event = Event::factory()->registration()->create();
    ut2004Recipe();

    SharedFile::factory()->approved()->create([
        'event_id' => $event->id,
        'original_name' => 'UT2004-DVD1.iso',
        'size_bytes' => 4_500_000_000,
    ]);

    LanomatServer::actingAs($user);

    LanomatServer::tool(RecipeInstallPlanTool::class, [
        'slug' => 'ut2004',
        'os' => 'windows',
    ])
        ->assertOk()
        ->assertSee('UT2004-DVD1.iso')
        ->assertSee('/api/v1/files/')
        ->assertStructuredContentPath('missing', [])
        ->assertStructuredContentPath('files.0.source', 'lan')
        ->assertStructuredContentCount(2, 'steps')
        ->assertStructuredContentPath('steps.1.optional', true);
});

it('reports a missing required file when nothing was uploaded', function () {
    $user = User::factory()->create();
    ut2004Recipe();

    LanomatServer::actingAs($user);

    $response = LanomatServer::tool(RecipeInstallPlanTool::class, ['slug' => 'ut2k4', 'os' => 'linux']);

    $response->assertSee('missing');
});

it('offers avatars as a multi-select and only when a LAN copy exists', function () {
    $user = User::factory()->create();
    $event = Event::factory()->registration()->create();
    ut2004Recipe();

    SharedFile::factory()->approved()->create([
        'event_id' => $event->id,
        'original_name' => 'Alyx.vrm',
        'size_bytes' => 12_000_000,
    ]);

    LanomatServer::actingAs($user);

    LanomatServer::tool(RecipeAvatarsTool::class, ['slug' => 'ut2004'])
        ->assertSee('Alyx.vrm')
        ->assertSee('available_on_lan');

    LanomatServer::tool(RecipeAvatarsTool::class, ['slug' => 'ut2004'])
        ->assertSee('CC-BY');
});

it('never matches pending files or files of a draft event', function () {
    $user = User::factory()->create();
    $event = Event::factory()->registration()->create();
    $draft = Event::factory()->create();
    ut2004Recipe();

    SharedFile::factory()->create(['event_id' => $event->id, 'original_name' => 'UT2004-pending.iso']);
    SharedFile::factory()->approved()->create(['event_id' => $draft->id, 'original_name' => 'UT2004-draft.iso']);

    LanomatServer::actingAs($user);

    LanomatServer::tool(RecipeInstallPlanTool::class, ['slug' => 'ut2004'])
        ->assertStructuredContentPath('files.0.status', 'missing');
});

it('exposes the skill text and the file list through recipe_get', function () {
    $user = User::factory()->create();
    ut2004Recipe();

    LanomatServer::actingAs($user);

    LanomatServer::tool(RecipeGetTool::class, ['slug' => 'unreal tournament', 'include_skill' => true])
        ->assertSee('name: ut2004')
        ->assertSee('UT2004 DVD');
});

it('answers me with the token user and enabled modules', function () {
    $user = User::factory()->create();

    LanomatServer::actingAs($user);

    LanomatServer::tool(MeTool::class)
        ->assertSee($user->name)
        ->assertSee('default_model');
});

it('shows draft recipes to orga and keeps them hidden from participants', function () {
    ut2004Recipe();
    GameRecipe::query()->create([
        'slug' => 'secret-draft',
        'title' => 'Secret',
        'status' => RecipeStatus::Draft,
        'source' => 'contribution',
        'platforms' => ['windows'],
        'steps' => [],
        'requirements' => [],
    ]);

    LanomatServer::actingAs(User::factory()->orga()->create());

    LanomatServer::tool(RecipesListTool::class)
        ->assertSee('unreal-tournament-2004')
        ->assertSee('secret-draft');
});

it('answers an unknown recipe with a readable error', function () {
    LanomatServer::actingAs(User::factory()->create());

    LanomatServer::tool(RecipeGetTool::class, ['slug' => 'no-such-recipe'])
        ->assertHasErrors(['Not found']);
});

it('contributes a valid recipe as a draft', function () {
    $user = User::factory()->create();

    LanomatServer::actingAs($user);

    LanomatServer::tool(RecipeContributeTool::class, [
        'slug' => 'counter-strike-16',
        'title' => 'Counter-Strike 1.6',
        'summary' => 'The classic.',
        'platforms' => ['windows'],
        'steps' => [[
            'id' => 'install',
            'title' => 'Install the base game',
            'os' => 'windows',
            'optional' => false,
            'detail' => 'Run the installer.',
            'commands' => [['shell' => 'powershell', 'run' => 'Start-Process setup.exe']],
            'verify' => [],
        ]],
        'skill_description' => 'Install Counter-Strike 1.6 from the LAN file host.',
    ])
        ->assertOk()
        ->assertStructuredContentPath('recipe.slug', 'counter-strike-16')
        ->assertStructuredContentPath('recipe.status', 'draft');

    $recipe = GameRecipe::query()->where('slug', 'counter-strike-16')->firstOrFail();

    expect($recipe->contributor_user_id)->toBe($user->id)
        ->and($recipe->status)->toBe(RecipeStatus::Draft);
});

it('lists every validation problem of an invalid contribution in one error', function () {
    LanomatServer::actingAs(User::factory()->create());

    LanomatServer::tool(RecipeContributeTool::class, ['slug' => 'Bad Slug', 'title' => ''])
        ->assertHasErrors([
            'slug must be lowercase-kebab-case',
            'title is required',
            'steps must be a non-empty list',
        ]);
});

it('forwards the extended contribution fields from recipe_contribute', function () {
    $user = User::factory()->create();
    $event = Event::factory()->registration()->create();
    $file = SharedFile::factory()->create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'original_name' => 'tiny-racer.zip',
    ]);

    LanomatServer::actingAs($user);

    LanomatServer::tool(RecipeContributeTool::class, [
        'slug' => 'tiny-racer',
        'title' => 'Tiny Racer',
        'version' => '1.2.3',
        'homepage' => 'https://tiny-racer.lan.example/',
        'license' => 'Freeware; the archive is user-supplied',
        'platforms' => ['windows'],
        'requires' => [[
            'role' => 'base',
            'label' => 'Tiny Racer archive',
            'match' => ['tiny-racer.zip'],
            'required' => true,
            'shared_file_id' => $file->id,
            'os' => 'windows',
            'notes' => 'Unpack the archive into the target folder.',
        ]],
        'steps' => [[
            'id' => 'check-install',
            'title' => 'Unpack and check',
            'os' => 'windows',
            'optional' => false,
            'detail' => 'Unpack the archive into the target folder.',
            'verify' => [['type' => 'file_exists', 'path' => '{{target}}/TinyRacer.exe']],
        ]],
        'launch_targets' => [[
            'id' => 'windows',
            'label' => 'Windows',
            'os' => 'windows',
            'command' => '{{target}}/TinyRacer.exe',
        ]],
        'portable' => true,
        'skill_description' => 'Install Tiny Racer from the LAN file host.',
    ])
        ->assertOk()
        ->assertStructuredContentPath('recipe.slug', 'tiny-racer')
        ->assertStructuredContentPath('recipe.status', 'draft')
        ->assertStructuredContentPath('recipe.launcher_supported', true)
        ->assertStructuredContentPath('recipe.pull_request', null);

    $recipe = GameRecipe::query()->where('slug', 'tiny-racer')->firstOrFail();

    expect($recipe->source_ref)->toBe('mcp')
        ->and($recipe->version)->toBe('1.2.3')
        ->and($recipe->homepage)->toBe('https://tiny-racer.lan.example/')
        ->and($recipe->license)->toBe('Freeware; the archive is user-supplied')
        ->and($recipe->metadata['portable'])->toBeTrue()
        ->and($recipe->launchTargets())->toHaveCount(1)
        ->and($recipe->files()->first()->shared_file_id)->toBe($file->id);
});

it('answers a pinned foreign file through recipe_contribute with a readable field error', function () {
    $user = User::factory()->create();
    $foreign = SharedFile::factory()->create(['original_name' => 'not-mine.zip']);

    LanomatServer::actingAs($user);

    LanomatServer::tool(RecipeContributeTool::class, [
        'slug' => 'pinned-foreign',
        'title' => 'Pinned Foreign',
        'platforms' => ['windows'],
        'requires' => [[
            'role' => 'base',
            'label' => 'Archive',
            'match' => ['not-mine.zip'],
            'required' => true,
            'shared_file_id' => $foreign->id,
        ]],
        'steps' => [[
            'id' => 'check-install',
            'title' => 'Unpack',
            'os' => 'windows',
            'verify' => [['type' => 'file_exists', 'path' => '{{target}}/game.exe']],
        ]],
    ])->assertHasErrors(['requirements.0.shared_file_id']);
});

it('reports launcher support and portability through recipe_get', function () {
    $user = User::factory()->create();
    GameRecipe::factory()->published()->portable()->create([
        'slug' => 'tiny-racer',
        'title' => 'Tiny Racer',
    ]);

    LanomatServer::actingAs($user);

    LanomatServer::tool(RecipeGetTool::class, ['slug' => 'tiny-racer'])
        ->assertOk()
        ->assertStructuredContentPath('recipe.launcher_supported', true)
        ->assertStructuredContentPath('recipe.portable', true);
});

it('shows a recipe pull request to its contributor and hides it from another participant', function () {
    $author = User::factory()->create();
    $stranger = User::factory()->create();

    $recipe = GameRecipe::factory()->published()->create([
        'slug' => 'own-contribution',
        'title' => 'Own Contribution',
        'source' => GameRecipe::SOURCE_CONTRIBUTION,
        'contributor_user_id' => $author->id,
    ]);
    $recipe->forceFill([
        'pr_number' => 42,
        'pr_url' => 'https://github.com/lanomat/recipes/pull/42',
        'pr_branch' => 'recipes/own-contribution',
        'pr_state' => PullRequestState::Open,
    ])->save();

    LanomatServer::actingAs($author);
    LanomatServer::tool(RecipeGetTool::class, ['slug' => 'own-contribution'])
        ->assertOk()
        ->assertStructuredContentPath('recipe.pull_request.number', 42);

    LanomatServer::actingAs($stranger);
    LanomatServer::tool(RecipeGetTool::class, ['slug' => 'own-contribution'])
        ->assertOk()
        ->assertStructuredContentPath('recipe.pull_request', null);
});

it('reports the lineup flag and the caller own pull request through recipes_list', function () {
    $author = User::factory()->create();

    $recipe = GameRecipe::factory()->contributedBy($author)->inLineup()->create([
        'slug' => 'own-draft',
        'title' => 'Own Draft',
    ]);
    $recipe->forceFill([
        'pr_number' => 11,
        'pr_url' => 'https://github.com/lanomat/recipes/pull/11',
        'pr_branch' => 'recipes/own-draft',
        'pr_state' => PullRequestState::Open,
    ])->save();

    LanomatServer::actingAs($author);

    LanomatServer::tool(RecipesListTool::class)
        ->assertOk()
        ->assertStructuredContentPath('recipes.0.slug', 'own-draft')
        ->assertStructuredContentPath('recipes.0.in_lineup', true)
        ->assertStructuredContentPath('recipes.0.pull_request.number', 11);
});
