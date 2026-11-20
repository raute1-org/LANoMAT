<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\Games\Models\Game;
use App\Modules\Lfg\Models\LfgPost;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function gamePageRecipe(string $status = 'published'): GameRecipe
{
    $recipe = GameRecipe::query()->create([
        'slug' => 'unreal-tournament-2004',
        'title' => 'Unreal Tournament 2004',
        'aliases' => ['ut2004', 'ut2k4', 'ut 2k4'],
        'version' => '3374',
        'status' => $status,
        'source' => 'bundled',
        'platforms' => ['windows', 'linux'],
        'summary' => 'Arena shooter from the LAN.',
        'description' => 'Install from the disc image.',
        'skill_markdown' => "---\nname: ut2004\ndescription: Install UT2004.\n---\n",
        'requirements' => [
            ['role' => 'iso', 'label' => 'UT2004 DVD', 'match' => ['ut2004*.iso'], 'required' => true],
        ],
        'steps' => [['id' => 'install', 'title' => 'Install', 'os' => 'windows', 'commands' => []]],
        'metadata' => ['launch' => 'C:\\Games\\UT2004\\System\\UT2004.exe', 'multiplayer' => true, 'players' => '2-32'],
    ]);

    $recipe->files()->create([
        'role' => 'iso',
        'label' => 'UT2004 DVD',
        'match_patterns' => ['ut2004*.iso'],
        'required' => true,
    ]);

    return $recipe;
}

it('lists published games with a LAN-ready flag and hides drafts', function () {
    $user = User::factory()->create();
    $event = Event::factory()->registration()->create();
    gamePageRecipe();

    GameRecipe::query()->create([
        'slug' => 'secret-draft',
        'title' => 'Secret',
        'status' => RecipeStatus::Draft,
        'source' => 'contribution',
        'platforms' => ['windows'],
        'requirements' => [],
        'steps' => [['id' => 'x', 'title' => 'x', 'os' => 'any', 'commands' => []]],
        'metadata' => ['genre' => 'x', 'players' => '1', 'lan_recommended' => false, 'tags' => []],
    ]);

    SharedFile::factory()->approved()->create([
        'event_id' => $event->id,
        'original_name' => 'UT2004-DVD1.iso',
    ]);

    $this->actingAs($user)
        ->get('/games')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Games/Index')
            ->has('games', 1)
            ->where('games.0.slug', 'unreal-tournament-2004')
            ->where('games.0.lan_ready', true)
            ->where('active_event.slug', $event->slug));
});

it('opens a game by alias and shows servers, tournaments and LFG', function () {
    $user = User::factory()->create();
    $event = Event::factory()->registration()->create();
    $recipe = gamePageRecipe();

    $game = Game::factory()->create([
        'name' => 'Unreal Tournament 2004',
        'slug' => 'unreal-tournament-2004',
    ]);

    $tournament = Tournament::factory()->for($event)->for($game)->create([
        'name' => 'UT2004 Cup',
        'status' => 'live',
    ]);

    (new LfgPost)->forceFill([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'game' => 'Unreal Tournament 2004',
        'title' => 'Runde UT2004',
        'body' => 'Wer spielt mit?',
        'slots_needed' => 4,
        'expires_at' => now()->addHour(),
    ])->save();

    $this->actingAs($user)
        ->get('/games/ut2k4')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Games/Show')
            ->where('game.slug', $recipe->slug)
            ->where('game.launch_target', 'C:\\Games\\UT2004\\System\\UT2004.exe')
            ->has('tournaments', 1)
            ->where('tournaments.0.id', $tournament->id)
            ->has('lfg', 1)
            ->has('library', 1));
});

it('announces a round in one click into the active event', function () {
    $user = User::factory()->create();
    $event = Event::factory()->registration()->create();
    gamePageRecipe();

    $this->actingAs($user)
        ->post('/games/unreal-tournament-2004/lfg')
        ->assertRedirect();

    expect(LfgPost::query()->where('event_id', $event->id)->where('user_id', $user->id)->exists())->toBeTrue();
});

it('404s an unknown or unpublished game', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/games/nope')->assertNotFound();

    gamePageRecipe('draft');
    $this->actingAs($user)->get('/games/unreal-tournament-2004')->assertNotFound();
});
