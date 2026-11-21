<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Support\FileStores;
use App\Modules\Files\Testing\FakeFileStore;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Models\GameRecipeInstall;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Support\McpCodes;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use App\Modules\WebVoice\NativeClient\Support\NativeClientConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

require_once __DIR__.'/PlayHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('launcher.lineup', '');
    $this->user = User::factory()->create();
    $this->headers = playBearer($this->user);
});

function voiceRecipe(array $overrides = []): GameRecipe
{
    $recipe = GameRecipe::factory()->published()->portable()->create($overrides + [
        'slug' => 'ut2004-test',
        'title' => 'UT Test',
    ]);
    $recipe->update(['metadata' => [...$recipe->metadata, 'genre' => 'fps', 'players' => '2-16', 'players_max' => 16]]);

    return $recipe;
}

/** A matched LAN file on a fake file host store so the plan can sign it. */
function voiceLanFile(GameRecipe $recipe, ?string $disk = 'filehost'): SharedFile
{
    $store = new FakeFileStore('filehost');
    app(FileStores::class)->extend($store);

    $event = Event::factory()->registration()->create();
    $file = SharedFile::factory()->approved()->create([
        'event_id' => $event->id,
        'disk' => $disk,
        'path' => 'event-'.$event->id.'/secret-storage-name',
        'original_name' => 'game-portable.zip',
        'size_bytes' => 4096,
        'sha256' => str_repeat('a', 64),
    ]);

    GameRecipeFile::factory()->for($recipe, 'recipe')->create([
        'role' => RecipeFileRole::Base,
        'label' => 'Portable archive',
        'match_patterns' => ['game-portable.zip'],
        'required' => true,
        'size_bytes' => 4096,
    ]);

    return $file;
}

it('requires a valid voice token on all three routes', function () {
    voiceRecipe();

    foreach (['recipes', 'recipes/ut2004-test', 'recipes/ut2004-test/plan'] as $path) {
        $this->getJson("/api/voice-client/v1/{$path}")->assertUnauthorized();
        $this->getJson("/api/voice-client/v1/{$path}", ['Authorization' => 'Bearer nope'])->assertUnauthorized();
    }
});

it('refuses revoked and expired tokens', function () {
    voiceRecipe();
    $headers = playBearer($this->user);
    $this->getJson('/api/voice-client/v1/recipes', $headers)->assertOk();

    VoiceClientToken::query()->latest('id')->first()->forceFill(['revoked_at' => now()])->save();
    $this->getJson('/api/voice-client/v1/recipes', $headers)->assertUnauthorized();

    $headers = playBearer($this->user);
    VoiceClientToken::query()->latest('id')->first()->forceFill(['expires_at' => now()->subSecond()])->save();
    $this->getJson('/api/voice-client/v1/recipes/ut2004-test/plan', $headers)->assertUnauthorized();
});

it('does not open the routes to an MCP token', function () {
    voiceRecipe();
    $token = 'lmcp_'.bin2hex(random_bytes(16));
    McpToken::query()->forceCreate([
        'user_id' => $this->user->id, 'name' => 't', 'token_hash' => McpCodes::hash($token),
        'abilities' => null, 'last_used_at' => now(), 'expires_at' => now()->addDay(),
    ]);

    $this->getJson('/api/voice-client/v1/recipes', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    $this->getJson('/api/voice-client/v1/recipes/ut2004-test/plan', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
});

it('lists published recipes with the catalogue fields and nothing else', function () {
    voiceRecipe();
    GameRecipe::factory()->create(['slug' => 'secret-draft', 'title' => 'Draft']);

    $response = $this->getJson('/api/voice-client/v1/recipes', $this->headers)->assertOk();

    expect(collect($response->json('recipes'))->pluck('slug')->all())->toBe(['ut2004-test']);
    $response->assertJsonPath('recipes.0.title', 'UT Test')
        ->assertJsonPath('recipes.0.genre', 'fps')
        ->assertJsonPath('recipes.0.players', '2-16')
        ->assertJsonPath('recipes.0.players_max', 16)
        ->assertJsonPath('recipes.0.installable', true)
        ->assertJsonStructure(['recipes' => [['slug', 'title', 'summary', 'version', 'status', 'platforms', 'genre', 'players', 'players_max', 'size_bytes', 'installable', 'launcher_supported', 'in_lineup', 'play_url', 'updated_at']]]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('respects the launcher lineup', function () {
    Config::set('launcher.lineup', 'in-lineup');
    voiceRecipe(['slug' => 'in-lineup', 'title' => 'In']);
    voiceRecipe(['slug' => 'not-in-lineup', 'title' => 'Out']);

    $this->getJson('/api/voice-client/v1/recipes', $this->headers)
        ->assertOk()
        ->assertJsonCount(1, 'recipes')
        ->assertJsonPath('recipes.0.slug', 'in-lineup');
});

it('shows one recipe and 404s drafts, unknown and hostile slugs', function () {
    voiceRecipe();
    GameRecipe::factory()->create(['slug' => 'secret-draft']);

    $this->getJson('/api/voice-client/v1/recipes/ut2004-test', $this->headers)
        ->assertOk()
        ->assertJsonPath('recipe.slug', 'ut2004-test')
        ->assertJsonPath('recipe.launcher_supported', fn ($v) => is_bool($v))
        ->assertJsonStructure(['recipe' => ['description', 'launch_targets', 'portable', 'supports_multiplayer']]);

    foreach (['secret-draft', 'nope', 'UT2004-TEST', 'a..b', '%2e%2e', 'ut2004_test'] as $slug) {
        $this->getJson("/api/voice-client/v1/recipes/{$slug}", $this->headers)->assertNotFound();
        $this->getJson("/api/voice-client/v1/recipes/{$slug}/plan", $this->headers)->assertNotFound();
    }
});

it('does not resolve aliases on the client routes', function () {
    voiceRecipe(['aliases' => ['utx']]);

    $this->getJson('/api/voice-client/v1/recipes/utx', $this->headers)->assertNotFound();
});

it('builds a plan with plan_version and signed voice links, never the MCP route', function () {
    $recipe = voiceRecipe();
    $file = voiceLanFile($recipe);

    $response = $this->getJson('/api/voice-client/v1/recipes/ut2004-test/plan?os=windows', $this->headers)->assertOk();

    expect($response->json('plan_version'))->toBe(NativeClientConfig::PLAN_VERSION)->toBe(1);

    $planFile = collect($response->json('files'))->firstWhere('status', 'matched');
    expect($planFile['download_url'])->toStartWith('https://files.fake.test/')
        ->and($planFile['download_url'])->not->toContain('/api/v1/files/')
        ->and($planFile['sha256'])->toBe(str_repeat('a', 64))
        ->and($planFile['shared_file_id'])->toBe($file->id)
        ->and($response->getContent())->not->toContain('secret-storage-name-leak');

    expect($response->json('downloads'))->toHaveCount(1)
        ->and($response->json('downloads.0.download_url'))->toBe($planFile['download_url']);
});

it('falls back to the bearer download route where no signed link exists', function () {
    $recipe = voiceRecipe();
    voiceLanFile($recipe, disk: 'local');
    Config::set('files.store', 'local');

    $response = $this->getJson('/api/voice-client/v1/recipes/ut2004-test/plan', $this->headers)->assertOk();
    $url = collect($response->json('files'))->firstWhere('status', 'matched')['download_url'];

    expect($url)->toContain('/api/voice-client/v1/files/')->and($url)->not->toContain('mcp');
});

it('records the plan request unless dry_run is set', function () {
    voiceRecipe();

    $this->getJson('/api/voice-client/v1/recipes/ut2004-test/plan?dry_run=1', $this->headers)->assertOk();
    expect(GameRecipeInstall::query()->count())->toBe(0);

    $this->getJson('/api/voice-client/v1/recipes/ut2004-test/plan', $this->headers)->assertOk();
    expect(GameRecipeInstall::query()->count())->toBe(1);
});

it('keeps the plan JSON compatible with the Rust RecipePlan and PlanFile types', function () {
    $recipe = voiceRecipe();
    voiceLanFile($recipe);

    $plan = $this->getJson('/api/voice-client/v1/recipes/ut2004-test/plan', $this->headers)->assertOk()->json();

    expect($plan)->toHaveKeys(['plan_version', 'recipe', 'os', 'target_dir', 'downloads_dir', 'files', 'missing', 'steps', 'avatars'])
        ->and($plan['recipe'])->toHaveKeys(['slug', 'title', 'version', 'launch_target', 'launch_targets', 'play_url']);

    foreach ($plan['files'] as $file) {
        expect($file)->toHaveKeys(['requirement', 'status', 'label', 'os', 'required', 'source']);
    }
});

it('rejects an invalid plan query', function () {
    voiceRecipe();

    $this->getJson('/api/voice-client/v1/recipes/ut2004-test/plan?os=plan9', $this->headers)->assertStatus(422);
    $this->getJson('/api/voice-client/v1/recipes/ut2004-test/plan?event_slug=nope', $this->headers)->assertNotFound();
});

it('advertises the launcher capability additively in the config', function () {
    $response = $this->getJson('/api/voice-client/v1/config', $this->headers)->assertOk();

    $response->assertJsonPath('api_version', 1)->assertJsonPath('features.launcher', true);
});

it('leaves files of unpublished events out of the plan', function () {
    $recipe = voiceRecipe();
    $file = voiceLanFile($recipe);
    $file->event()->update(['status' => 'draft']);

    $plan = $this->getJson('/api/voice-client/v1/recipes/ut2004-test/plan', $this->headers)->assertOk()->json();

    expect(collect($plan['files'])->pluck('status')->all())->not->toContain('matched')
        ->and(json_encode($plan))->not->toContain('files.fake.test');
});
