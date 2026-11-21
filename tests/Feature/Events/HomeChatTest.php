<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

// The chat fixtures are a broad catalogue; the lineup filter is covered by
// tests/Feature/GameRecipes/LauncherLineupTest.php.
beforeEach(function () {
    Config::set('launcher.lineup', '');
});

/**
 * A published recipe with one required LAN file, so `installable` is true and
 * the games library's LAN-readiness rule has something to match.
 */
function homeChatRecipe(string $slug = 'arcade-racer', string $title = 'Arcade Racer'): GameRecipe
{
    $recipe = GameRecipe::factory()->published()->create([
        'slug' => $slug,
        'title' => $title,
        'summary' => 'Schnelle Runden auf der LAN.',
        'version' => '1.2',
        'requirements' => [
            ['role' => 'iso', 'label' => 'Disc', 'match' => [$slug.'.iso'], 'required' => true],
        ],
    ]);

    $recipe->files()->create([
        'role' => 'iso',
        'label' => 'Disc',
        'match_patterns' => [$slug.'.iso'],
        'required' => true,
    ]);

    return $recipe;
}

function homeChatGame(): Game
{
    return Game::factory()->create([
        'name' => 'Unreal Tournament 2004',
        'slug' => 'unreal-tournament-2004',
    ]);
}

it('treats the guest root as a login gate without chat, event or launcher data', function () {
    Event::factory()->registration()->create(['name' => 'Zebrakuchen LAN 2099']);
    homeChatRecipe();
    GameRecipe::factory()->draft()->create([
        'slug' => 'secret-draft',
        'title' => 'Secret Draft',
    ]);

    // A permanent server exists, but guests never see aggregated server data.
    ServerLink::factory()->permanent()->create(['game_id' => homeChatGame()->id]);

    $response = $this->get('/')->assertOk();

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Welcome')
        ->has('labels')
        ->missing('chat')
        // Shared, lazy and null for guests: the key exists but carries no event.
        ->where('currentEvent', null)
    );

    expect((string) $response->getContent())
        ->toContain('Mit Discord anmelden')
        ->not->toContain('Zebrakuchen')
        ->not->toContain('Secret Draft')
        ->not->toContain('lanomat-launcher')
        ->not->toContain('Launcher herunterladen');
});

it('answers logged-in visitors with games and aggregated servers', function () {
    $event = Event::factory()->registration()->create(['name' => 'Raute LAN']);
    homeChatRecipe('unreal-tournament-2004', 'Unreal Tournament 2004');
    $game = homeChatGame();

    ServerLink::factory()->create([
        'event_id' => $event->id,
        'game_id' => $game->id,
        'title' => 'Event Server',
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.20', port: 7777),
        'query_info' => ['name' => 'Raute UT', 'map' => 'DM-Rankin', 'players' => 4, 'max_players' => 16],
    ]);

    ServerLink::factory()->permanent()->create([
        'game_id' => $game->id,
        'title' => 'Dauerserver',
    ]);
    // Pending servers have nothing joinable and stay out of the chat.
    ServerLink::factory()->create([
        'event_id' => $event->id,
        'game_id' => $game->id,
        'title' => 'Noch nicht bereit',
        'status' => ServerLinkStatus::Pending,
    ]);

    $response = $this->actingAs(User::factory()->create())->get('/')->assertOk();

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Now')
        ->has('chat.greeting')
        ->has('chat.games', 1)
        ->where('chat.games.0.lan_ready', true)
        ->where('chat.games.0.launcher_supported', true)
        ->where('chat.games.0.server_count', 2)
        ->has('chat.servers', 2)
    );

    $props = $response->viewData('page')['props'];
    $servers = collect($props['chat']['servers'])->keyBy('title');

    expect($props['chat']['servers'])->toHaveCount(2)
        ->and($servers)->toHaveKeys(['Dauerserver', 'Event Server'])
        ->and($servers['Event Server']['event_slug'])->toBe($event->slug)
        ->and($servers['Event Server']['map'])->toBe('DM-Rankin')
        ->and($servers['Event Server']['players'])->toBe(4)
        ->and($servers['Event Server']['max'])->toBe(16)
        ->and($servers['Event Server']['join_url'])->toContain('steam://')
        ->and($servers['Dauerserver']['event_slug'])->toBeNull();
});

it('offers the chat hero on the archive page too', function () {
    Event::factory()->finished()->create(['name' => 'Alte LAN']);
    ServerLink::factory()->permanent()->create([
        'game_id' => homeChatGame()->id,
        'title' => 'Dauerserver',
    ]);

    $this->actingAs(User::factory()->create())->get('/events')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Event/Index')
            ->has('chat.greeting')
            ->has('chat.games', 0)
            ->has('chat.servers', 1)
            ->where('chat.servers.0.title', 'Dauerserver')
        );
});

it('renders the chat with empty answer sets when both optional modules are off', function () {
    homeChatRecipe();
    ServerLink::factory()->permanent()->create(['game_id' => homeChatGame()->id]);

    app()->instance(Modules::class, new Modules('full', [
        'game_recipes' => false,
        'game_servers' => false,
    ]));

    $response = $this->actingAs(User::factory()->create())->get('/')->assertOk();

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->has('chat.greeting')
        ->has('chat.suggestions', 2)
        ->has('chat.games', 0)
        ->has('chat.servers', 0)
    );

    $props = $response->viewData('page')['props'];
    expect($props['chat']['greeting'])->not->toBe('');
});

it('serves the dock chat data as json for signed-in users', function () {
    $event = Event::factory()->live()->create();
    $game = homeChatGame();
    homeChatRecipe('unreal-tournament-2004', 'Unreal Tournament 2004');
    ServerLink::factory()->create([
        'event_id' => $event->id,
        'game_id' => $game->id,
        'status' => ServerLinkStatus::Ready,
    ]);

    $this->get('/chat')->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->getJson('/chat')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('suggestions.0', 'Welche Spiele laufen?')
        ->assertJsonPath('games.0.slug', 'unreal-tournament-2004')
        ->assertJsonCount(1, 'games')
        ->assertJsonCount(1, 'servers');
});

it('keeps a live game in the cards even when its title is last alphabetically', function () {
    $event = Event::factory()->live()->create();

    foreach (['aaa', 'bbb', 'ccc', 'ddd', 'eee', 'fff'] as $slug) {
        homeChatRecipe($slug, strtoupper($slug));
    }

    $live = Game::factory()->create(['slug' => 'zzz', 'name' => 'Zzz']);
    homeChatRecipe('zzz', 'Zzz');
    ServerLink::factory()->create([
        'event_id' => $event->id,
        'game_id' => $live->id,
        'status' => ServerLinkStatus::Ready,
    ]);

    $response = $this->actingAs(User::factory()->create())->get('/')->assertOk();

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->has('chat.games', 6)
        ->where('chat.games.0.slug', 'zzz')
        ->where('chat.games.0.server_count', 1));
});

it('caps the chat at six games and eight servers', function () {
    $event = Event::factory()->registration()->create();
    $game = homeChatGame();

    foreach (range(1, 8) as $index) {
        homeChatRecipe("game-{$index}", "Game {$index}");
        ServerLink::factory()->permanent()->create([
            'game_id' => $game->id,
            'title' => sprintf('Dauerserver %02d', $index),
        ]);
    }

    $this->actingAs(User::factory()->create())->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('chat.games', 6)
            ->has('chat.servers', 8)
        );
});

it('leaks no contact or registration data into the chat cards', function () {
    $event = Event::factory()->registration()->create(['name' => 'Raute LAN']);
    homeChatRecipe('unreal-tournament-2004', 'Unreal Tournament 2004');
    ServerLink::factory()->permanent()->create([
        'game_id' => homeChatGame()->id,
        'title' => 'Dauerserver',
    ]);

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('chat.servers.0.address')
            ->missing('chat.servers.0.connectString')
            ->missing('chat.servers.0.query')
            ->missing('chat.registration')
            ->missing('chat.event')
        );
});

it('marks only the launcher pilot recipe as launcher-supported', function () {
    homeChatRecipe('unreal-tournament-2004', 'Unreal Tournament 2004');
    homeChatRecipe('arcade-racer', 'Arcade Racer');

    $response = $this->actingAs(User::factory()->create())->get('/')->assertOk();

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->has('chat.games', 2)
    );

    $games = collect($response->viewData('page')['props']['chat']['games'])->keyBy('slug');

    expect($games['unreal-tournament-2004']['launcher_supported'])->toBeTrue()
        ->and($games['arcade-racer']['launcher_supported'])->toBeFalse();
});

it('points the install action at /launcher when a build is published', function () {
    $dir = sys_get_temp_dir().'/lanomat-home-chat-'.bin2hex(random_bytes(6));
    mkdir($dir);

    $name = 'lanomat-launcher-v1-linux-x86_64.tar.gz';
    $content = 'launcher-binary';
    file_put_contents($dir.'/'.$name, $content);
    file_put_contents($dir.'/manifest.json', json_encode([
        'app_version' => 'v1',
        'commit' => 'abc123def456',
        'built_at' => '2026-11-20T10:00:00Z',
        'files' => [[
            'name' => $name,
            'os' => 'linux',
            'arch' => 'x86_64',
            'kind' => 'app',
            'variant' => 'archive',
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'signed' => null,
        ]],
    ], JSON_THROW_ON_ERROR));

    Config::set('launcher.dist_path', $dir);
    homeChatRecipe('unreal-tournament-2004', 'Unreal Tournament 2004');

    try {
        $this->actingAs(User::factory()->create())->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('chat.games.0.launcher_url', '/launcher')
                ->where('chat.games.0.launcher_supported', true)
                ->where('chat.games.0.install_deeplink', 'lanomat://install/unreal-tournament-2004')
            );
    } finally {
        File::deleteDirectory($dir);
    }
});

it('keeps the home query count bounded', function () {
    $event = Event::factory()->registration()->create();
    $game = homeChatGame();

    foreach (range(1, 6) as $index) {
        homeChatRecipe("game-{$index}", "Game {$index}");
    }

    foreach (range(1, 8) as $index) {
        ServerLink::factory()->permanent()->create([
            'game_id' => $game->id,
            'title' => sprintf('Dauerserver %02d', $index),
        ]);
    }

    ServerLink::factory()->create([
        'event_id' => $event->id,
        'game_id' => $game->id,
        'title' => 'Event Server',
        'status' => ServerLinkStatus::Ready,
    ]);

    $user = User::factory()->create();
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($user)->get('/')->assertOk();

    expect($queries)->toBeLessThan(60);
});

it('places the chat hero above the fold on every authenticated start page', function () {
    $pages = [
        'resources/js/pages/Event/Show.vue' => 'data-test="event-launcher"',
        'resources/js/pages/Event/Index.vue' => 'aria-labelledby="events-current"',
    ];

    foreach ($pages as $file => $belowTheFoldMarker) {
        $source = file_get_contents(base_path($file));
        $chatAt = strpos($source, '<HomeChat');
        $markerAt = strpos($source, $belowTheFoldMarker);

        expect($chatAt)->not->toBeFalse("{$file} does not render HomeChat.")
            ->and($markerAt)->not->toBeFalse("{$file} lost its expected content marker.")
            ->and($chatAt)->toBeLessThan($markerAt);
    }
});

it('keeps the guest welcome page a login gate without a chat or launcher link', function () {
    $source = (string) file_get_contents(base_path('resources/js/pages/Welcome.vue'));

    expect($source)
        ->not->toContain('<HomeChat')
        ->not->toContain('/launcher')
        ->not->toContain('Launcher herunterladen')
        ->toContain('data-test="welcome-login-discord"');
});
