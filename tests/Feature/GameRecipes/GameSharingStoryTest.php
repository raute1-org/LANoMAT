<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Actions\PublishRecipe;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Mcp\Actions\DecideDeviceAuthorization;
use App\Modules\Mcp\Actions\ExchangeDeviceCode;
use App\Modules\Mcp\Actions\StartDeviceAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * The whole journey in one test, across the modules it touches: a participant
 * shares a game, the orga approves and publishes it in one step, another
 * participant finds it on /games, in the launcher catalogue and next to its
 * server, and the recipe travels into the git catalogue as a pull request.
 *
 * Each step has its own detailed tests elsewhere; this one only proves that
 * the pieces fit, with a curated lineup and pull requests switched on.
 */
it('takes a shared game from upload to a joined server and a merged pull request', function () {
    $this->withoutVite();
    Config::set('launcher.lineup', 'unreal-tournament-2004');
    Config::set('launcher.dist_path', base_path('clients-dist/__missing-for-tests__'));
    Config::set('recipes.pull_requests.trigger', 'contribute');
    $repository = fakeRecipeRepository();
    $repository->files['resources/recipes/_catalog.md'] = implode("\n", [
        '# Recipe catalog',
        '',
        '## Published',
        '',
        '| Slug | Title | Platforms | Status | Summary |',
        '| --- | --- | --- | --- | --- |',
        '| `unreal-tournament-2004` | Unreal Tournament 2004 | windows, linux | published | Arena shooter. |',
        '',
        '## Draft',
        '',
        '| Slug | Title | Platforms | Status | Summary |',
        '| --- | --- | --- | --- | --- |',
        '| `another-game` | Another Game | windows | draft | Something else. |',
        '',
    ]);

    $event = Event::factory()->registration()->create();
    $sharer = User::factory()->create();
    $orga = User::factory()->orga()->create();
    $player = User::factory()->create();

    // 1. Share: the participant's own upload still waits for approval.
    $archive = SharedFile::factory()->create([
        'user_id' => $sharer->id,
        'event_id' => $event->id,
        'original_name' => 'tiny-racer.zip',
    ]);

    $this->actingAs($sharer)
        ->post('/games/share', [
            'title' => 'Tiny Racer',
            'shared_file_id' => $archive->id,
            'platforms' => ['windows', 'linux'],
            'executable' => 'bin/TinyRacer.exe',
            'linux_runner' => 'wine',
            'multiplayer' => true,
        ])
        ->assertRedirect(route('games.share'))
        ->assertSessionHasNoErrors();

    $recipe = GameRecipe::query()->where('slug', 'tiny-racer')->sole();

    expect($recipe->status)->toBe(RecipeStatus::Draft)
        ->and($recipe->contributor_user_id)->toBe($sharer->id)
        ->and($recipe->files()->first()->shared_file_id)->toBe($archive->id);

    // 2. Keep: sharing already proposed the recipe text, and only the text.
    $repository->assertProposed('tiny-racer');
    $proposal = $repository->lastProposal();

    expect($proposal->branch)->toBe('recipes/tiny-racer')
        ->and(array_keys($proposal->files))->toBe([
            'resources/recipes/tiny-racer/recipe.yaml',
            'resources/recipes/tiny-racer/SKILL.md',
            'resources/recipes/_catalog.md',
        ])
        ->and($proposal->files['resources/recipes/_catalog.md'])->toContain('| `tiny-racer` | Tiny Racer |')
        ->and(implode("\n", $proposal->files))->not->toContain('shared_file_id')
        ->and($proposal->body)->not->toContain($sharer->name)
        ->and($recipe->refresh()->pr_state)->toBe(PullRequestState::Open);

    // Until the orga acts, nobody else sees the game.
    $this->actingAs($player)->get('/games')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('games', []));

    // 3. Review: one step approves the file and publishes the recipe.
    app(PublishRecipe::class)->handle($recipe, $orga, approveFiles: true);

    expect($archive->refresh()->visibility)->toBe(FileVisibility::Approved)
        ->and($recipe->refresh()->status)->toBe(RecipeStatus::Published)
        ->and($recipe->in_lineup)->toBeTrue();

    // 4. Find: on /games although the configured lineup does not name it.
    $this->actingAs($player)->get('/games')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('games', 1)
            ->where('games.0.slug', 'tiny-racer')
            ->where('games.0.launcher_supported', true));

    // The launcher catalogue offers it, and its plan finds the approved archive.
    $pair = app(StartDeviceAuthorization::class)->handle('story-test');
    app(DecideDeviceAuthorization::class)->handle($player, $pair['user_code'], true);
    $token = app(ExchangeDeviceCode::class)->handle($pair['device_code'])['access_token'];

    $catalogue = collect($this->withToken($token)->getJson('/api/v1/recipes')->assertOk()->json('data'))
        ->firstWhere('slug', 'tiny-racer');

    expect($catalogue)->not->toBeNull()
        ->and($catalogue['installable'])->toBeTrue()
        ->and($catalogue['launcher_supported'])->toBeTrue();

    $plan = $this->withToken($token)
        ->postJson('/api/v1/recipes/tiny-racer/plan', ['os' => 'windows', 'dry_run' => true])
        ->assertOk()
        ->json();

    expect($plan['missing'])->toBe([])
        ->and($plan['files'][0]['status'])->toBe('matched')
        ->and($plan['files'][0]['shared_file_id'])->toBe($archive->id)
        ->and($plan['files'][0]['download_url'])->toContain('/api/v1/files/'.$archive->id.'/download');

    // 5. Join: a server of that game leads straight to it.
    $game = Game::factory()->create(['slug' => 'tiny-racer', 'name' => 'Tiny Racer']);
    ServerLink::factory()->create([
        'event_id' => $event->id,
        'game_id' => $game->id,
        'title' => 'Tiny Racer LAN',
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.5', port: 27015, connectString: 'steam://connect/203.0.113.5:27015'),
    ]);

    $this->actingAs($player)->get('/servers/all')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('servers.0.install.recipeSlug', 'tiny-racer')
            ->where('servers.0.install.pageUrl', '/games/tiny-racer')
            ->where('servers.0.install.launcherSupported', true)
            ->where('servers.0.install.launcherJoinUrl', fn (?string $url): bool => is_string($url)
                && str_starts_with($url, 'lanomat://join/tiny-racer?')));

    // The sharer sees where their game stands.
    $this->actingAs($sharer)->get('/games/share')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('shares.0.slug', 'tiny-racer')
            ->where('shares.0.status', 'published')
            ->where('shares.0.gameUrl', '/games/tiny-racer')
            ->where('shares.0.file.visibility', 'approved')
            ->where('shares.0.pullRequest.state', 'open'));

    // 6. Publishing moved the catalogue row; a maintainer merges; the sync notices.
    expect($repository->lastProposal()->files['resources/recipes/tiny-racer/recipe.yaml'])->toContain('status: published');

    $repository->states[$recipe->refresh()->pr_number] = PullRequestState::Merged;

    $this->artisan('lanomat:recipes:pr-status')->assertSuccessful();

    expect($recipe->refresh()->pr_state)->toBe(PullRequestState::Merged);
});
