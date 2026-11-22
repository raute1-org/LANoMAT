<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\HomeChatData;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\ServerListProjection;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * Every server surface reads the recipe catalogue once per request or job, never once per row.
 *
 * A page asks "which recipe installs this game?" for each of its servers, and the shell's
 * server badge asks the same question on the same page; a queue worker or the scheduler asks
 * it for a list of rows. The bindings hand out one resolver per request/job, and the builders
 * of {@see ServerListProjection} pass it down their loops.
 */
function catalogueReadEvent(): Event
{
    return Event::factory()->live()->create();
}

function catalogueReadGame(): Game
{
    return Game::factory()->create(['slug' => 'tiny-racer', 'name' => 'Tiny Racer']);
}

/** An event-level server that answers, reachable at `203.0.113.5:7777`. */
function catalogueReadServer(Event $event, Game $game, string $title): ServerLink
{
    return ServerLink::factory()->create([
        'event_id' => $event->id,
        'game_id' => $game->id,
        'title' => $title,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.5', port: 7777),
    ]);
}

beforeEach(function () {
    Config::set('launcher.lineup', '');
    Config::set('launcher.dist_path', base_path('clients-dist/__missing-for-tests__'));
});

it('reads the catalogue once for a server page with ten servers of one game', function () {
    $event = catalogueReadEvent();
    $game = catalogueReadGame();
    $viewer = User::factory()->create();

    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $viewer->id]);
    GameRecipe::factory()->published()->create(['slug' => 'tiny-racer', 'title' => 'Tiny Racer']);

    foreach (range(0, 9) as $index) {
        catalogueReadServer($event, $game, 'Server '.$index);
    }

    $recipeQueries = 0;

    DB::listen(function ($query) use (&$recipeQueries): void {
        if (str_contains($query->sql, 'game_recipes')) {
            $recipeQueries++;
        }
    });

    $this->actingAs($viewer)
        ->get("/servers?event={$event->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('servers', 10)
            ->where('servers.0.install.recipeSlug', 'tiny-racer'));

    // One read for the ten rows and the sidebar's server badge together, not one per row.
    expect($recipeQueries)->toBe(1);
});

it('reads the catalogue once for a list of ten servers built outside a web request', function () {
    $event = catalogueReadEvent();
    $game = catalogueReadGame();

    GameRecipe::factory()->published()->create(['slug' => 'tiny-racer', 'title' => 'Tiny Racer']);

    foreach (range(0, 9) as $index) {
        catalogueReadServer($event, $game, 'Server '.$index);
    }

    $recipeQueries = 0;

    DB::listen(function ($query) use (&$recipeQueries): void {
        if (str_contains($query->sql, 'game_recipes')) {
            $recipeQueries++;
        }
    });

    // A queue worker or the scheduler has no routed request: the builder takes the resolver
    // once and hands it to every row.
    $rows = ServerListProjection::forEvent($event, withPlayerList: true);

    expect($rows)->toHaveCount(10)
        ->and($recipeQueries)->toBe(1);
});

it('does not keep a resolver between two builds outside a web request', function () {
    $event = catalogueReadEvent();
    $game = catalogueReadGame();
    catalogueReadServer($event, $game, 'Server 0');

    expect(ServerListProjection::forEvent($event)[0]['install'])->toBeNull();

    // A recipe published later must be seen by the next build of a long-lived worker.
    GameRecipe::factory()->published()->create(['slug' => 'tiny-racer', 'title' => 'Tiny Racer']);

    expect(ServerListProjection::forEvent($event)[0]['install']['recipeSlug'] ?? null)->toBe('tiny-racer');
});

it('reads the catalogue once for the chat server strip outside a web request', function () {
    $event = catalogueReadEvent();
    $game = catalogueReadGame();

    GameRecipe::factory()->published()->create(['slug' => 'tiny-racer', 'title' => 'Tiny Racer']);
    catalogueReadServer($event, $game, 'Server 0');

    $recipeQueries = 0;

    DB::listen(function ($query) use (&$recipeQueries): void {
        if (str_contains($query->sql, 'game_recipes')) {
            $recipeQueries++;
        }
    });

    // The chat takes the resolver once for its whole card strip, so eight servers cost the
    // same catalogue reads as one (before the fix every row resolved a fresh one).
    app(HomeChatData::class)->forEvent($event);
    $oneServer = $recipeQueries;

    foreach (range(1, 7) as $index) {
        catalogueReadServer($event, $game, 'Server '.$index);
    }

    app(HomeChatData::class)->forEvent($event);

    expect($recipeQueries - $oneServer)->toBe($oneServer);
});
