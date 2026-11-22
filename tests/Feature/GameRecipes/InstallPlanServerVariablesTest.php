<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\BuildInstallPlan;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Mcp\Actions\DecideDeviceAuthorization;
use App\Modules\Mcp\Actions\ExchangeDeviceCode;
use App\Modules\Mcp\Actions\StartDeviceAuthorization;
use App\Modules\Mcp\Server\LanomatServer;
use App\Modules\Mcp\Tools\RecipeGetTool;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Plan variables `{{server_host}}` and `{{server_port}}` (game-sharing plan
 * section 8.2, decision G4): a recipe never names this installation's server.
 * `BuildInstallPlan` fills both in before the plan leaves the server, from a
 * server of that recipe's game the viewer may see anyway. Anything that is not
 * a plain host name / a port between 1 and 65535 resolves to an empty string,
 * so a server row can never break out of the quoted shell argument it lands in.
 */

/** @param list<array<string, mixed>> $steps @param list<array<string, mixed>> $launchTargets */
function planVariableRecipe(string $slug, array $steps = [], array $launchTargets = []): GameRecipe
{
    return GameRecipe::query()->create([
        'slug' => $slug,
        'title' => Str::title(str_replace('-', ' ', $slug)),
        'status' => 'published',
        'source' => 'bundled',
        'platforms' => ['windows', 'linux'],
        'summary' => 'Plan variable test recipe.',
        'requirements' => [],
        'steps' => $steps !== [] ? $steps : [[
            'id' => 'configure',
            'title' => 'Configure the LAN server',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Point the game at the LAN server.',
            'commands' => [['shell' => 'bash', 'run' => 'cfg --host="{{server_host}}" --port="{{server_port}}"']],
            'verify' => [
                ['type' => 'file_exists', 'path' => '{{target}}/{{server_host}}.ini'],
                ['type' => 'command', 'command' => 'ping {{server_host}}', 'args' => ['-p', '{{server_port}}']],
            ],
        ]],
        'metadata' => [
            'launch_targets' => $launchTargets !== [] ? $launchTargets : [[
                'id' => 'default',
                'label' => 'Start',
                'os' => 'any',
                'command' => 'game --connect "{{server_host}}:{{server_port}}"',
            ]],
        ],
        'skill_markdown' => "# plan-variable-test\n",
    ])->load('files');
}

/** @return array<string, mixed> */
function planVariablePlan(GameRecipe $recipe, User $viewer, ?Event $event = null): array
{
    return app(BuildInstallPlan::class)->handle($recipe, $viewer, 'windows', $event);
}

function planVariableGame(string $slug, string $name): Game
{
    return Game::query()->updateOrCreate(
        ['slug' => $slug],
        ['name' => $name, 'min_team_size' => 1, 'max_team_size' => 8],
    );
}

function planVariableServer(Game $game, ?string $address, ?int $port, array $attributes = []): ServerLink
{
    return ServerLink::factory()->create(array_merge([
        'game_id' => $game->id,
        'title' => 'LAN server',
        'permanent' => true,
        'status' => ServerLinkStatus::Ready,
        'source' => ServerSource::External,
        'join_info' => new JoinInfo(address: $address, port: $port),
    ], $attributes));
}

it('fills the plan variables in step commands, step checks and launch targets', function () {
    $recipe = planVariableRecipe('lan-shooter');
    planVariableServer(planVariableGame('lan-shooter', 'LAN Shooter'), '203.0.113.9', 27015);

    $plan = planVariablePlan($recipe, User::factory()->create());
    $step = $plan['steps'][0];

    expect($step['commands'][0]['run'])->toBe('cfg --host="203.0.113.9" --port="27015"')
        ->and($step['verify'][0]['path'])->toBe('{{target}}/203.0.113.9.ini')
        ->and($step['verify'][1]['command'])->toBe('ping 203.0.113.9')
        ->and($step['verify'][1]['args'])->toBe(['-p', '27015'])
        ->and($plan['recipe']['launch_targets'][0]['command'])->toBe('game --connect "203.0.113.9:27015"')
        ->and($plan['recipe']['launch_target'])->toBe('game --connect "203.0.113.9:27015"')
        // The clients still substitute {{target}}/{{downloads}} themselves.
        ->and($step['verify'][0]['path'])->toContain('{{target}}')
        // No placeholder of the new pair survives anywhere in the plan.
        ->and((string) json_encode($plan))->not->toContain('{{server_');
});

it('resolves both plan variables to an empty string when no server of the game is visible', function () {
    $recipe = planVariableRecipe('lan-shooter');
    planVariableGame('lan-shooter', 'LAN Shooter');
    planVariableServer(planVariableGame('ridge-racer-type-4', 'Ridge Racer Type 4'), '203.0.113.20', 8080);

    $plan = planVariablePlan($recipe, User::factory()->create());
    $step = $plan['steps'][0];

    expect($step['commands'][0]['run'])->toBe('cfg --host="" --port=""')
        ->and($step['verify'][0]['path'])->toBe('{{target}}/.ini')
        ->and($step['verify'][1]['args'])->toBe(['-p', ''])
        ->and($plan['recipe']['launch_targets'][0]['command'])->toBe('game --connect ":"');
});

it('resolves a hostile server address to empty strings', function (string $address) {
    $slug = 'lan-shooter-'.substr(md5($address), 0, 8);
    $recipe = planVariableRecipe($slug);
    planVariableServer(planVariableGame($slug, 'LAN Shooter'), $address, 27015);

    $plan = planVariablePlan($recipe, User::factory()->create());

    // A row that cannot become a quoted argument is no server at all, so the
    // step leaves the game's configuration alone on the port too.
    expect($plan['steps'][0]['commands'][0]['run'])->toBe('cfg --host="" --port=""')
        ->and($plan['recipe']['launch_targets'][0]['command'])->toBe('game --connect ":"');
})->with([
    'quote break-out' => ["'; calc.exe; '"],
    'command substitution' => ['$(id)'],
    'backticks' => ['`id`'],
    'space' => ['a b'],
    'another placeholder' => ['{{target}}'],
    'line break' => ["203.0.113.9\n27015"],
    'ipv6 literal' => ['::1'],
    'too long' => [str_repeat('a', 300)],
    'url' => ['https://lan.example/'],
]);

it('resolves an impossible port to an empty string and keeps the host', function (?int $port) {
    $slug = 'lan-shooter-'.substr(md5('port-'.var_export($port, true)), 0, 8);
    $recipe = planVariableRecipe($slug);
    planVariableServer(planVariableGame($slug, 'LAN Shooter'), '203.0.113.9', $port);

    $plan = planVariablePlan($recipe, User::factory()->create());

    expect($plan['steps'][0]['commands'][0]['run'])->toBe('cfg --host="203.0.113.9" --port=""')
        ->and($plan['recipe']['launch_targets'][0]['command'])->toBe('game --connect "203.0.113.9:"');
})->with([0, -1, 70000, null]);

it('asks for no server at all when the recipe does not use the plan variables', function () {
    $recipe = planVariableRecipe('plain-shooter', steps: [[
        'id' => 'unpack',
        'title' => 'Unpack',
        'os' => 'any',
        'optional' => false,
        'detail' => 'Unpack the archive.',
        'commands' => [['shell' => 'bash', 'run' => 'unzip "{{downloads}}/game.zip" -d "{{target}}"']],
        'verify' => [['type' => 'file_exists', 'path' => '{{target}}/game.exe']],
    ]], launchTargets: [[
        'id' => 'default', 'label' => 'Start', 'os' => 'any', 'command' => 'start "{{target}}/game.exe"',
    ]]);
    planVariableGame('plain-shooter', 'Plain Shooter');

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $plan = planVariablePlan($recipe, User::factory()->create());

    expect($plan['steps'][0]['commands'][0]['run'])->toBe('unzip "{{downloads}}/game.zip" -d "{{target}}"')
        ->and($plan['steps'][0]['verify'][0]['path'])->toBe('{{target}}/game.exe')
        ->and(collect($queries)->filter(static fn (string $sql): bool => str_contains($sql, 'server_links')))->toBeEmpty();
});

it('never takes the server of a game that merely resembles the recipe', function () {
    // The NFSU2 case: the browser twin's public front end shares the native
    // game's name. Its address must never be written into the native game's
    // configuration, least of all when it is the only server there is.
    $recipe = planVariableRecipe('lan-shooter');
    planVariableServer(planVariableGame('lan-shooter-browser', 'LAN Shooter (Browser)'), 'play.lan.example', 443);

    $alone = planVariablePlan($recipe, User::factory()->create());

    expect($alone['steps'][0]['commands'][0]['run'])->toBe('cfg --host="" --port=""')
        ->and($alone['recipe']['launch_targets'][0]['command'])->toBe('game --connect ":"');

    planVariableServer(planVariableGame('lan-shooter', 'LAN Shooter'), '203.0.113.9', 27015);

    $both = planVariablePlan($recipe, User::factory()->create());

    expect($both['steps'][0]['commands'][0]['run'])->toBe('cfg --host="203.0.113.9" --port="27015"');
});

it('prefers a server that answers, then a permanent one', function () {
    $event = Event::factory()->registration()->create();
    $recipe = planVariableRecipe('lan-shooter');
    $game = planVariableGame('lan-shooter', 'LAN Shooter');

    // A permanent server that does not answer loses against an event server that does.
    planVariableServer($game, '203.0.113.41', 4001, ['status' => ServerLinkStatus::NotResponding]);

    ServerLink::factory()->create([
        'event_id' => $event->id,
        'game_id' => $game->id,
        'title' => 'Event server',
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.40', port: 4000),
    ]);

    $plan = planVariablePlan($recipe, User::factory()->create(), $event);

    expect($plan['steps'][0]['commands'][0]['run'])->toBe('cfg --host="203.0.113.40" --port="4000"');

    // Among servers that answer, the permanent one wins although its id is higher.
    planVariableServer($game, '203.0.113.42', 4002);

    $plan = planVariablePlan($recipe, User::factory()->create(), $event);

    expect($plan['steps'][0]['commands'][0]['run'])->toBe('cfg --host="203.0.113.42" --port="4002"');
});

it('never names a server that is switched off or still being set up', function (ServerLinkStatus $status) {
    $recipe = planVariableRecipe('lan-shooter');
    planVariableServer(planVariableGame('lan-shooter', 'LAN Shooter'), '203.0.113.60', 6000, ['status' => $status]);

    $plan = planVariablePlan($recipe, User::factory()->create());

    expect($plan['steps'][0]['commands'][0]['run'])->toBe('cfg --host="" --port=""');
})->with([
    'offline' => [ServerLinkStatus::Offline],
    'provisioning' => [ServerLinkStatus::Provisioning],
]);

it('fills the variables of a legacy launch target as well', function () {
    $recipe = planVariableRecipe('lan-shooter', launchTargets: [['id' => 'default', 'label' => 'Start', 'os' => 'any', 'command' => 'game']]);
    $recipe->forceFill(['steps' => [[
        'id' => 'unpack', 'title' => 'Unpack', 'os' => 'any', 'optional' => false, 'detail' => 'Unpack.',
        'commands' => [['shell' => 'bash', 'run' => 'true']],
    ]], 'metadata' => ['launch' => ['kind' => 'exe', 'target' => 'game', 'args' => ['--connect', '{{server_host}}']]]])->save();
    planVariableServer(planVariableGame('lan-shooter', 'LAN Shooter'), '203.0.113.9', 27015);

    $plan = planVariablePlan($recipe->refresh()->load('files'), User::factory()->create());

    expect(json_encode($plan['recipe']))->not->toContain('{{server_host}}')
        ->and(json_encode($plan['recipe']))->toContain('203.0.113.9');
});

it('falls back to the lowest id when two servers rank alike', function () {
    $recipe = planVariableRecipe('lan-shooter');
    $game = planVariableGame('lan-shooter', 'LAN Shooter');

    $first = planVariableServer($game, '203.0.113.50', 5000);
    planVariableServer($game, '203.0.113.51', 5001);

    $plan = planVariablePlan($recipe, User::factory()->create());

    expect($first->id)->toBeLessThan(ServerLink::query()->max('id'))
        ->and($plan['steps'][0]['commands'][0]['run'])->toBe('cfg --host="203.0.113.50" --port="5000"');
});

it('uses a server of a public event but not one of a draft or archived event', function () {
    $recipe = planVariableRecipe('lan-shooter');
    $game = planVariableGame('lan-shooter', 'LAN Shooter');

    $draft = Event::factory()->draft()->create();
    ServerLink::factory()->create([
        'event_id' => $draft->id,
        'game_id' => $game->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.60', port: 6000),
    ]);

    $plan = planVariablePlan($recipe, User::factory()->create());
    expect($plan['steps'][0]['commands'][0]['run'])->toBe('cfg --host="" --port=""');

    $live = Event::factory()->live()->create();
    $link = ServerLink::factory()->create([
        'event_id' => $live->id,
        'game_id' => $game->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.61', port: 6001),
    ]);

    $plan = planVariablePlan($recipe, User::factory()->create());
    expect($link->id)->toBeInt()
        ->and($plan['steps'][0]['commands'][0]['run'])->toBe('cfg --host="203.0.113.61" --port="6001"');
});

it('only names a match server to the players of that match and to orga', function () {
    $event = Event::factory()->live()->create();
    $game = planVariableGame('lan-shooter', 'LAN Shooter');
    $recipe = planVariableRecipe('lan-shooter');

    $tournament = Tournament::factory()->for($event)->for($game)->create();
    $match = GameMatch::factory()->for($tournament)->create([
        'entry1_id' => TournamentEntry::factory()->for($tournament)->create()->id,
        'entry2_id' => TournamentEntry::factory()->for($tournament)->create()->id,
    ]);

    ServerLink::factory()->create([
        'match_id' => $match->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.70', port: 7000),
    ]);

    $outsider = User::factory()->create();
    $plan = planVariablePlan($recipe, $outsider, $event);
    expect($plan['steps'][0]['commands'][0]['run'])->toBe('cfg --host="" --port=""');

    $orga = User::factory()->orga()->create();
    $plan = planVariablePlan($recipe, $orga, $event);
    expect($plan['steps'][0]['commands'][0]['run'])->toBe('cfg --host="203.0.113.70" --port="7000"');
});

it('resolves both plan variables to an empty string while GameServers is off', function () {
    $recipe = planVariableRecipe('lan-shooter');
    planVariableServer(planVariableGame('lan-shooter', 'LAN Shooter'), '203.0.113.9', 27015);

    app()->instance(Modules::class, new Modules('core'));

    $plan = planVariablePlan($recipe, User::factory()->create());

    expect($plan['steps'][0]['commands'][0]['run'])->toBe('cfg --host="" --port=""');
});

it('hands no raw plan variable to a client that reads the recipe detail', function () {
    // The Node agent takes its launch targets from the recipe detail, not from
    // the plan: the detail must carry the same filled-in text.
    $recipe = planVariableRecipe('lan-shooter');
    planVariableServer(planVariableGame('lan-shooter', 'LAN Shooter'), '203.0.113.9', 27015);
    $user = User::factory()->create();

    $pair = app(StartDeviceAuthorization::class)->handle('plan-variable-test');
    app(DecideDeviceAuthorization::class)->handle($user, $pair['user_code'], true);
    $token = app(ExchangeDeviceCode::class)->handle($pair['device_code'])['access_token'];

    $detail = $this->withToken($token)->getJson('/api/v1/recipes/'.$recipe->slug)->assertOk()->json('recipe');

    expect($detail['launch_targets'][0]['command'])->toBe('game --connect "203.0.113.9:27015"')
        ->and($detail['steps'][0]['commands'][0]['run'])->toBe('cfg --host="203.0.113.9" --port="27015"')
        ->and($detail['steps'][0]['verify'][1]['args'])->toBe(['-p', '27015'])
        ->and(json_encode($detail['launch_targets']).json_encode($detail['steps']))->not->toContain('{{server_');

    LanomatServer::actingAs($user);

    LanomatServer::tool(RecipeGetTool::class, ['slug' => $recipe->slug])
        ->assertOk()
        ->assertSee('203.0.113.9')
        ->assertDontSee('{{server_host}}');
});
