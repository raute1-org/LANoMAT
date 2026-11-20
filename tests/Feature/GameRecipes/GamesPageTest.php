<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Lfg\Models\LfgPost;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

/** The browser-arcade case: no installer, no files, no multiplayer — just a URL. */
function ridgeRacerRecipe(): GameRecipe
{
    return GameRecipe::query()->create([
        'slug' => 'ridge-racer-type-4',
        'title' => 'Ridge Racer Type 4',
        'aliases' => ['r4', 'rr4'],
        'version' => '1.0.0',
        'status' => 'published',
        'source' => 'bundled',
        'platforms' => ['windows', 'linux'],
        'summary' => 'Browser arcade cabinet.',
        'requirements' => [],
        'steps' => [],
        'metadata' => ['play_url' => 'http://localhost:8080/', 'multiplayer' => false, 'players' => '1'],
    ]);
}

function gamePageLfg(Event $event, User $user, string $gameTitle): LfgPost
{
    $post = (new LfgPost)->forceFill([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'game' => $gameTitle,
        'title' => 'Runde '.$gameTitle,
        'body' => 'Wer spielt mit?',
        'slots_needed' => 4,
        'expires_at' => now()->addHour(),
    ]);
    $post->save();

    return $post;
}

it('lists published games with LAN state, counts and hides drafts', function () {
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
            ->where('games.0.installable', true)
            ->where('games.0.server_count', 0)
            ->where('games.0.running_tournaments_count', 0)
            ->where('games.0.open_lfg', 0)
            ->where('filters.only_lan', false)
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

    gamePageLfg($event, $user, 'Unreal Tournament 2004');

    $this->actingAs($user)
        ->get('/games/ut2k4')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Games/Show')
            ->where('game.slug', $recipe->slug)
            ->where('game.launch_target', 'C:\\Games\\UT2004\\System\\UT2004.exe')
            ->where('game.running_tournaments_count', 1)
            ->has('tournaments', 1)
            ->where('tournaments.0.id', $tournament->id)
            ->has('lfg', 1)
            ->has('library', 1)
            ->where('library.0.slug', 'unreal-tournament-2004')
            ->where('library.0.lan_ready', false));
});

it('counts a running server on the card, the detail page and the sidebar', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    $recipe = gamePageRecipe();
    $game = Game::factory()->create(['name' => 'Unreal Tournament 2004', 'slug' => $recipe->slug]);
    $tournament = Tournament::factory()->for($event)->for($game)->live()->create(['name' => 'UT2004 Cup']);

    ServerLink::factory()->permanent()->create([
        'game_id' => $game->id,
        'title' => 'UT2004 Dauerserver',
    ]);

    $this->actingAs($user)
        ->get('/games')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // A live server makes the game LAN-ready even without the install image.
            ->where('games.0.lan_ready', true)
            ->where('games.0.server_count', 1)
            ->where('games.0.running_tournaments_count', 1));

    $this->actingAs($user)
        ->get('/games/unreal-tournament-2004')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('game.lan_ready', true)
            ->where('game.server_count', 1)
            ->has('servers', 1)
            ->where('servers.0.title', 'UT2004 Dauerserver')
            ->has('tournaments', 1)
            ->where('tournaments.0.id', $tournament->id));
});

it('filters only LAN-ready games on the server when ?only_lan=1 is given', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    gamePageRecipe();

    GameRecipe::query()->create([
        'slug' => 'not-on-the-lan',
        'title' => 'Not On The LAN',
        'status' => 'published',
        'source' => 'bundled',
        'platforms' => ['windows'],
        'requirements' => [],
        'steps' => [],
        'metadata' => ['multiplayer' => true],
    ]);

    SharedFile::factory()->approved()->create([
        'event_id' => $event->id,
        'original_name' => 'UT2004-DVD1.iso',
    ]);

    $this->actingAs($user)
        ->get('/games')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('games', 2)
            ->where('filters.only_lan', false));

    $this->actingAs($user)
        ->get('/games?only_lan=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('games', 1)
            ->where('games.0.slug', 'unreal-tournament-2004')
            ->where('filters.only_lan', true));
});

it('renders the singleplayer browser-only game without agent, files or servers', function () {
    $user = User::factory()->create();
    Event::factory()->live()->create();
    ridgeRacerRecipe();

    $this->actingAs($user)
        ->get('/games')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('games', 1)
            ->where('games.0.slug', 'ridge-racer-type-4')
            ->where('games.0.installable', false)
            ->where('games.0.supports_multiplayer', false)
            ->where('games.0.lan_ready', false)
            ->where('games.0.server_count', 0));

    $this->actingAs($user)
        ->get('/games/r4')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Games/Show')
            ->where('game.slug', 'ridge-racer-type-4')
            ->where('game.installable', false)
            ->where('game.requires_agent', false)
            ->where('game.play_url', 'http://localhost:8080/')
            ->where('game.supports_multiplayer', false)
            ->has('game.requirements', 0)
            ->has('servers', 0)
            ->has('tournaments', 0)
            ->has('lfg', 0));
});

it('keeps the library open when no event is active and ignores files of draft events', function () {
    $user = User::factory()->create();
    $draft = Event::factory()->draft()->create();
    gamePageRecipe();

    SharedFile::factory()->approved()->create([
        'event_id' => $draft->id,
        'original_name' => 'UT2004-DVD1.iso',
    ]);

    $this->actingAs($user)
        ->get('/games')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('active_event', null)
            ->has('games', 1)
            // A file uploaded to a draft event is not "on the LAN".
            ->where('games.0.lan_ready', false));

    $this->actingAs($user)
        ->get('/games/unreal-tournament-2004')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('active_event', null)
            ->has('tournaments', 0)
            ->has('lfg', 0)
            ->where('game.lan_ready', false));
});

it('does not list a tournament without a game on every recipe', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    gamePageRecipe();

    Tournament::factory()->for($event)->live()->create(['name' => 'No game cup', 'game_id' => null]);

    $this->actingAs($user)
        ->get('/games')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('games.0.running_tournaments_count', 0));
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

it('keeps the games library at a constant query count as the catalogue grows', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();

    $seed = function (int $from, int $to) use ($event, $user): void {
        for ($i = $from; $i <= $to; $i++) {
            $slug = 'library-game-'.$i;
            $recipe = GameRecipe::query()->create([
                'slug' => $slug,
                'title' => 'Library Game '.$i,
                'aliases' => ['lg'.$i],
                'status' => 'published',
                'source' => 'bundled',
                'platforms' => ['windows'],
                'summary' => 'Seeded game '.$i,
                'requirements' => [['role' => 'iso', 'label' => 'Disc '.$i, 'match' => [$slug.'.iso'], 'required' => true]],
                'steps' => [],
                'metadata' => ['multiplayer' => true],
            ]);
            $recipe->files()->create([
                'role' => 'iso',
                'label' => 'Disc '.$i,
                'match_patterns' => [$slug.'.iso'],
                'required' => true,
            ]);

            $game = Game::factory()->create(['name' => 'Library Game '.$i, 'slug' => $slug]);

            SharedFile::factory()->approved()->create([
                'event_id' => $event->id,
                'original_name' => $slug.'.iso',
            ]);
            Tournament::factory()->for($event)->for($game)->live()->create(['name' => 'Cup '.$i]);
            ServerLink::factory()->permanent()->create(['game_id' => $game->id, 'title' => 'Server '.$i]);
            gamePageLfg($event, $user, 'Library Game '.$i);
        }
    };

    $seed(1, 2);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($user)->get('/games')->assertOk();
    $small = $queries;

    $seed(3, 8);

    $queries = 0;
    $this->actingAs($user)->get('/games')->assertOk();
    $large = $queries;

    // Adding six recipes (with files, servers, tournaments and LFG posts) must
    // not add a single query: the page batches each relation once.
    expect($large)->toBe($small)
        ->and($large)->toBeLessThan(25);
});

it('shows the seeded LAN image on the file panel and marks the card LAN-ready', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();

    // The ScreenshotSeeder shape: YAML requirements and game_recipe_files rows
    // in the same order, the ISO pinned through shared_file_id.
    $recipe = GameRecipe::query()->create([
        'slug' => 'unreal-tournament-2004',
        'title' => 'Unreal Tournament 2004',
        'aliases' => ['ut2004', 'ut2k4'],
        'version' => '3374',
        'status' => 'published',
        'source' => 'bundled',
        'platforms' => ['windows', 'linux'],
        'summary' => 'Arena shooter from the LAN disc image.',
        'requirements' => [
            ['role' => 'iso', 'label' => 'UT2004 DVD 1', 'match' => ['ut2004*.iso'], 'required' => true],
            ['role' => 'patch', 'label' => 'OldUnreal 3374', 'match' => ['ut2004patch*.zip'], 'required' => true],
            ['role' => 'avatar', 'label' => 'VRM avatar · Alyx', 'match' => ['alyx*.vrm'], 'required' => false],
        ],
        'steps' => [['id' => 'install', 'title' => 'Base Game installieren', 'os' => 'windows', 'optional' => false, 'detail' => '']],
        'metadata' => ['launch' => 'C:\\Games\\UT2004\\System\\UT2004.exe', 'multiplayer' => true],
    ]);

    foreach ([
        ['role' => 'iso', 'label' => 'UT2004 DVD 1', 'match_patterns' => ['ut2004*.iso'], 'required' => true, 'sort' => 0],
        ['role' => 'patch', 'label' => 'OldUnreal 3374', 'match_patterns' => ['ut2004patch*.zip'], 'required' => true, 'sort' => 1],
        ['role' => 'avatar', 'label' => 'VRM avatar · Alyx', 'match_patterns' => ['alyx*.vrm'], 'required' => false, 'sort' => 2],
    ] as $file) {
        $recipe->files()->create($file);
    }

    $shared = SharedFile::factory()->approved()->create([
        'event_id' => $event->id,
        'original_name' => 'UT2004-DVD1.iso',
        'size_bytes' => 4_500_000_000,
    ]);

    $recipe->files()->where('label', 'UT2004 DVD 1')->update(['shared_file_id' => $shared->id]);

    $this->actingAs($user)
        ->get('/games')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('games.0.slug', 'unreal-tournament-2004')
            ->where('games.0.lan_ready', true));

    $this->actingAs($user)
        ->get('/games/unreal-tournament-2004')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('game.lan_ready', true)
            ->where('game.requirements.0.label', 'UT2004 DVD 1')
            ->where('game.requirements.0.available_on_lan', true)
            ->where('game.requirements.0.name', 'UT2004-DVD1.iso')
            ->where('game.requirements.0.size_bytes', 4_500_000_000)
            ->where('game.requirements.1.available_on_lan', false));
});

it('pairs requirements with file rows by role and label, not by list position', function () {
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();

    $recipe = GameRecipe::query()->create([
        'slug' => 'unreal-tournament-2004',
        'title' => 'Unreal Tournament 2004',
        'status' => 'published',
        'source' => 'bundled',
        'platforms' => ['windows'],
        'summary' => 'Arena shooter.',
        'requirements' => [
            ['role' => 'iso', 'label' => 'UT2004 DVD 1', 'match' => ['ut2004*.iso'], 'required' => true],
            ['role' => 'patch', 'label' => 'OldUnreal 3374', 'match' => ['ut2004patch*.zip'], 'required' => true],
            ['role' => 'avatar', 'label' => 'VRM avatar · Alyx', 'match' => ['alyx*.vrm'], 'required' => false],
        ],
        'steps' => [],
        'metadata' => ['multiplayer' => true],
    ]);

    // Rows deliberately sort in the opposite order of the YAML requirements:
    // an index-based pairing would put the avatar row against the ISO.
    $recipe->files()->create(['role' => 'avatar', 'label' => 'VRM avatar · Alyx', 'match_patterns' => ['alyx*.vrm'], 'required' => false, 'sort' => 0]);
    $recipe->files()->create(['role' => 'patch', 'label' => 'OldUnreal 3374', 'match_patterns' => ['ut2004patch*.zip'], 'required' => true, 'sort' => 1]);
    $iso = $recipe->files()->create(['role' => 'iso', 'label' => 'UT2004 DVD 1', 'match_patterns' => ['ut2004*.iso'], 'required' => true, 'sort' => 2]);

    $shared = SharedFile::factory()->approved()->create([
        'event_id' => $event->id,
        'original_name' => 'UT2004-DVD1.iso',
        'size_bytes' => 4_500_000_000,
    ]);
    $iso->update(['shared_file_id' => $shared->id]);

    $this->actingAs($user)
        ->get('/games/unreal-tournament-2004')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('game.lan_ready', true)
            ->where('game.requirements.0.label', 'UT2004 DVD 1')
            ->where('game.requirements.0.available_on_lan', true)
            ->where('game.requirements.0.name', 'UT2004-DVD1.iso')
            ->where('game.requirements.1.label', 'OldUnreal 3374')
            ->where('game.requirements.1.available_on_lan', false)
            ->where('game.requirements.2.label', 'VRM avatar · Alyx')
            ->where('game.requirements.2.available_on_lan', false));
});

it('exposes the avatar converter URL only when the recipe configures it', function () {
    $user = User::factory()->create();
    Event::factory()->live()->create();
    $recipe = gamePageRecipe();

    // Not configured: no link, so the page never renders an empty button.
    $this->actingAs($user)
        ->get('/games/unreal-tournament-2004')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('game.avatar_converter_url', null));

    $recipe->update(['metadata' => [
        'launch' => 'C:\\Games\\UT2004\\System\\UT2004.exe',
        'multiplayer' => true,
        'avatar_converter_url' => 'https://ut.raute1.org/',
    ]]);

    $this->actingAs($user)
        ->get('/games')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('games.0.avatar_converter_url', 'https://ut.raute1.org/'));

    $this->actingAs($user)
        ->get('/games/unreal-tournament-2004')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('game.avatar_converter_url', 'https://ut.raute1.org/'));

    // A non-http(s) scheme is ignored instead of becoming a clickable link.
    $recipe->update(['metadata' => [
        'launch' => 'C:\\Games\\UT2004\\System\\UT2004.exe',
        'multiplayer' => true,
        'avatar_converter_url' => 'javascript:alert(1)',
    ]]);

    $this->actingAs($user)
        ->get('/games/unreal-tournament-2004')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('game.avatar_converter_url', null));
});
